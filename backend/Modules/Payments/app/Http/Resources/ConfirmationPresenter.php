<?php

namespace Modules\Payments\Http\Resources;

use Modules\Payments\Models\MpesaConfirmation;

/** Shapes a confirmation for the till (minimal) and the back office (with its sale). */
final class ConfirmationPresenter
{
    /** @return array<string, mixed> */
    public static function till(MpesaConfirmation $c): array
    {
        return [
            'id' => $c->id,
            'receipt' => $c->receipt,
            'amountCents' => $c->amount_cents,
            'source' => $c->source,
            'phoneMasked' => $c->phone_masked,
            'payerName' => $c->payer_name,
            'transactedAt' => $c->transacted_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function backOffice(MpesaConfirmation $c): array
    {
        $sale = $c->tender?->sale;

        return [
            ...self::till($c),
            'billReference' => $c->bill_reference,
            'allocated' => $c->tender_id !== null,
            'sale' => $sale ? ['id' => $sale->id, 'number' => $sale->number] : null,
            'allocatedBy' => $c->allocator?->name,
            'allocatedAt' => $c->allocated_at?->toIso8601String(),
        ];
    }
}
