<?php

namespace Modules\Payments\Contracts;

use Modules\Payments\Support\StkStatus;

/** Talks to Safaricom (or a stand-in). Bound in PaymentsServiceProvider from payments.mpesa.driver. */
interface MpesaGateway
{
    /**
     * Sends the PIN prompt to the customer's phone.
     *
     * @param  string  $phone  2547XXXXXXXX
     * @return array{merchantRequestId: string, checkoutRequestId: string}
     */
    public function stkPush(string $phone, int $amountCents, string $reference, string $description): array;

    /** Asks Daraja what happened to a request whose callback has not arrived. */
    public function stkQuery(string $checkoutRequestId): StkStatus;

    /**
     * Registers the C2B validation/confirmation URLs for the till or paybill.
     *
     * @return array<string, mixed>
     */
    public function registerC2bUrls(string $confirmationUrl, string $validationUrl): array;
}
