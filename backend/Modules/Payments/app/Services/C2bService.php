<?php

namespace Modules\Payments\Services;

use Carbon\CarbonImmutable;
use Modules\Payments\Models\MpesaConfirmation;

/**
 * Customer-initiated payments (Buy Goods till or Paybill). Daraja posts a confirmation
 * for each; it lands in the unallocated pool until a till or manager matches it to a sale.
 */
class C2bService
{
    /**
     * Daraja C2B confirmation body: TransID, TransAmount, MSISDN, FirstName, BillRefNumber, TransTime…
     *
     * @param  array<string, mixed>  $body
     */
    public function record(array $body): ?MpesaConfirmation
    {
        $receipt = strtoupper(trim((string) ($body['TransID'] ?? '')));
        if ($receipt === '' || ! isset($body['TransAmount'])) {
            return null;
        }

        $name = trim(implode(' ', array_filter([$body['FirstName'] ?? null, $body['MiddleName'] ?? null, $body['LastName'] ?? null])));
        // MSISDN may be hashed by Safaricom; keep only a masked form either way.
        $payload = $body;
        unset($payload['MSISDN']);

        return MpesaConfirmation::query()->firstOrCreate(['receipt' => $receipt], [
            'source' => MpesaConfirmation::SOURCE_C2B,
            'amount_cents' => (int) round(((float) $body['TransAmount']) * 100),
            'phone_masked' => MpesaConfirmation::mask($body['MSISDN'] ?? null),
            'payer_name' => $name !== '' ? mb_substr($name, 0, 120) : null,
            'shortcode' => isset($body['BusinessShortCode']) ? (string) $body['BusinessShortCode'] : null,
            'bill_reference' => isset($body['BillRefNumber']) ? mb_substr((string) $body['BillRefNumber'], 0, 40) : null,
            'transacted_at' => $this->time($body['TransTime'] ?? null),
            'payload' => $payload,
        ]);
    }

    private function time(mixed $value): CarbonImmutable
    {
        $parsed = is_string($value) || is_int($value) ? CarbonImmutable::createFromFormat('YmdHis', (string) $value, 'Africa/Nairobi') : false;

        return $parsed ?: CarbonImmutable::now();
    }
}
