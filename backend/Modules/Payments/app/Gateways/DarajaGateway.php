<?php

namespace Modules\Payments\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Contracts\MpesaGateway;
use Modules\Payments\Support\StkStatus;
use RuntimeException;

/**
 * Safaricom Daraja API (M-PESA Express + C2B). Credentials come from payments.mpesa.*.
 * Amounts are whole shillings on the wire; Daraja rejects cents.
 */
class DarajaGateway implements MpesaGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function stkPush(string $phone, int $amountCents, string $reference, string $description): array
    {
        $timestamp = now('Africa/Nairobi')->format('YmdHis');
        $response = $this->client()->post('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $this->config['shortcode'],
            'Password' => $this->password($timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => $this->config['transaction_type'],
            'Amount' => intdiv($amountCents, 100), // StkPushService only sends whole shillings
            'PartyA' => $phone,
            'PartyB' => $this->config['party_b'] ?: $this->config['shortcode'],
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->callbackUrl('stk'),
            'AccountReference' => mb_substr($reference, 0, 12),
            'TransactionDesc' => mb_substr($description, 0, 13),
        ]);

        if ($response->failed() || $response->json('ResponseCode') !== '0') {
            throw new RuntimeException($response->json('errorMessage') ?? $response->json('ResponseDescription') ?? 'M-PESA did not accept the request.');
        }

        return [
            'merchantRequestId' => (string) $response->json('MerchantRequestID'),
            'checkoutRequestId' => (string) $response->json('CheckoutRequestID'),
        ];
    }

    public function stkQuery(string $checkoutRequestId): StkStatus
    {
        $timestamp = now('Africa/Nairobi')->format('YmdHis');
        $response = $this->client()->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $this->config['shortcode'],
            'Password' => $this->password($timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);

        // "The transaction is being processed" comes back as an error until the customer acts.
        if ($response->failed() || $response->json('ResultCode') === null) {
            return new StkStatus(StkStatus::PENDING, resultDescription: $response->json('errorMessage'));
        }

        $code = (string) $response->json('ResultCode');

        // The query does not return the receipt number; a paid request waits for its callback.
        return new StkStatus(
            $code === '0' ? StkStatus::PENDING : StkStatus::FAILED,
            resultCode: $code,
            resultDescription: $response->json('ResultDesc'),
            payload: (array) $response->json(),
        );
    }

    public function registerC2bUrls(string $confirmationUrl, string $validationUrl): array
    {
        $response = $this->client()->post('/mpesa/c2b/v1/registerurl', [
            'ShortCode' => $this->config['party_b'] ?: $this->config['shortcode'],
            'ResponseType' => 'Completed',
            'ConfirmationURL' => $confirmationUrl,
            'ValidationURL' => $validationUrl,
        ]);

        if ($response->failed()) {
            throw new RuntimeException($response->json('errorMessage') ?? 'Could not register the C2B URLs.');
        }

        return (array) $response->json();
    }

    public function callbackUrl(string $kind): string
    {
        return rtrim((string) $this->config['callback_base_url'], '/')."/api/v1/payments/mpesa/callbacks/{$this->config['callback_token']}/{$kind}";
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())->acceptJson()->timeout(20)->withToken($this->accessToken());
    }

    private function accessToken(): string
    {
        // Tokens last an hour; refresh a little early.
        return Cache::remember('mpesa.daraja.token', 3300, function () {
            $response = Http::baseUrl($this->baseUrl())
                ->withBasicAuth((string) $this->config['consumer_key'], (string) $this->config['consumer_secret'])
                ->timeout(20)
                ->get('/oauth/v1/generate', ['grant_type' => 'client_credentials']);

            return $response->json('access_token') ?? throw new RuntimeException('Could not sign in to Daraja. Check the consumer key and secret.');
        });
    }

    private function password(string $timestamp): string
    {
        return base64_encode($this->config['shortcode'].$this->config['passkey'].$timestamp);
    }

    private function baseUrl(): string
    {
        return $this->config['environment'] === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }
}
