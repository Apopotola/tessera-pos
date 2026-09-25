<?php

namespace Modules\Compliance\Support;

/** KRA's answer to one submission. */
final readonly class EtimsResult
{
    public const SIGNED = 'signed';

    /** Data refused: do not retry until someone fixes it. */
    public const REJECTED = 'rejected';

    /** KRA unreachable / timed out: retry later. */
    public const RETRY = 'retry';

    /** @param array<string, mixed> $response */
    public function __construct(
        public string $outcome,
        public ?string $invoiceNumber = null,
        public ?string $signature = null,
        public ?string $internalData = null,
        public ?string $qrPayload = null,
        public ?string $scuId = null,
        public ?string $error = null,
        public array $response = [],
    ) {}

    public static function retry(string $error): self
    {
        return new self(self::RETRY, error: $error);
    }

    public static function rejected(string $error, array $response = []): self
    {
        return new self(self::REJECTED, error: $error, response: $response);
    }
}
