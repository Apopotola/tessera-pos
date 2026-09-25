<?php

namespace Modules\Payments\Gateways;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Payments\Contracts\MpesaGateway;
use Modules\Payments\Support\StkStatus;

/**
 * Local development and tests without Daraja credentials. The "customer" pays a few
 * seconds after the prompt; a phone ending in 000 declines (to try the failure path).
 */
class FakeGateway implements MpesaGateway
{
    public function __construct(private readonly int $delaySeconds) {}

    public function stkPush(string $phone, int $amountCents, string $reference, string $description): array
    {
        $checkout = 'ws_CO_FAKE_'.Str::upper(Str::random(16));
        Cache::put("mpesa.fake.{$checkout}", ['phone' => $phone, 'amount' => $amountCents, 'at' => now()->getTimestamp()], 3600);

        return ['merchantRequestId' => 'FAKE-'.Str::upper(Str::random(8)), 'checkoutRequestId' => $checkout];
    }

    public function stkQuery(string $checkoutRequestId): StkStatus
    {
        $request = Cache::get("mpesa.fake.{$checkoutRequestId}");
        if (! $request) {
            return new StkStatus(StkStatus::FAILED, '1037', 'Request not found');
        }
        if (now()->getTimestamp() - $request['at'] < $this->delaySeconds) {
            return new StkStatus(StkStatus::PENDING);
        }
        if (str_ends_with($request['phone'], '000')) {
            return new StkStatus(StkStatus::FAILED, '1032', 'Request cancelled by user');
        }

        // Unlike Daraja's query, the fake reports the receipt so the flow completes without a callback.
        return new StkStatus(
            StkStatus::PAID,
            '0',
            'The service request is processed successfully.',
            receipt: 'FK'.Str::upper(Str::random(8)),
            amountCents: $request['amount'],
            transactedAt: now('Africa/Nairobi')->format('YmdHis'),
            payload: ['fake' => true],
        );
    }

    public function registerC2bUrls(string $confirmationUrl, string $validationUrl): array
    {
        return ['ResponseDescription' => 'Fake driver: nothing to register', 'ConfirmationURL' => $confirmationUrl];
    }
}
