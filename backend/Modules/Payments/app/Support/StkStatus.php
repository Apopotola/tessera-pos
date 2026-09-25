<?php

namespace Modules\Payments\Support;

/** Outcome of an STK Push, from the callback or a status query. */
final readonly class StkStatus
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public function __construct(
        public string $state,
        public ?string $resultCode = null,
        public ?string $resultDescription = null,
        public ?string $receipt = null,
        public ?int $amountCents = null,
        public ?string $transactedAt = null,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}

    /**
     * Daraja STK callback body (Body.stkCallback) or query response.
     * ResultCode 0 = paid; 1032 cancelled; 1037 timeout; 2001 wrong PIN; 1 insufficient funds.
     *
     * @param  array<string, mixed>  $callback
     */
    public static function fromCallback(array $callback): self
    {
        $code = (string) ($callback['ResultCode'] ?? '');
        $items = collect($callback['CallbackMetadata']['Item'] ?? [])->mapWithKeys(fn ($i) => [$i['Name'] => $i['Value'] ?? null]);

        return new self(
            state: $code === '0' ? self::PAID : self::FAILED,
            resultCode: $code,
            resultDescription: $callback['ResultDesc'] ?? null,
            receipt: isset($items['MpesaReceiptNumber']) ? (string) $items['MpesaReceiptNumber'] : null,
            amountCents: isset($items['Amount']) ? (int) round(((float) $items['Amount']) * 100) : null,
            transactedAt: isset($items['TransactionDate']) ? (string) $items['TransactionDate'] : null,
            payload: $callback,
        );
    }
}
