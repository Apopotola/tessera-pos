<?php

namespace Modules\Compliance\Contracts;

use Modules\Compliance\Support\EtimsResult;

/**
 * Talks to KRA eTIMS. The mock (FakeEtimsGateway) serves demos; a VSCU or OSCU driver
 * implementing this interface replaces it without touching sales code.
 */
interface EtimsGateway
{
    /** Sends a sales invoice or credit note; the payload's invoice number is the idempotency key. */
    public function submit(array $payload): EtimsResult;

    /** After a timeout: did KRA already accept this invoice number? */
    public function find(string $invoiceNumber): ?EtimsResult;
}
