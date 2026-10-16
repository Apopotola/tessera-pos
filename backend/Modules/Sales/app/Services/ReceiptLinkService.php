<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Sales\Http\Controllers\TillSaleController;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleReceiptLink;

/**
 * Shareable receipt links. The token (48 random letters and digits, about 285 bits) is shown once,
 * in the link; the database keeps only its SHA-256 hash.
 */
class ReceiptLinkService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** A new link for the sale; returns the token for /r/{token}. */
    public function create(Sale $sale, User $user): string
    {
        $token = Str::random(48);

        DB::transaction(function () use ($sale, $user, $token) {
            $link = SaleReceiptLink::query()->create([
                'sale_id' => $sale->id,
                'token_hash' => hash('sha256', $token),
                'created_by' => $user->id,
            ]);
            $this->audit->log('sales.receipt_link.created', $sale, after: ['link_id' => $link->id], userId: $user->id, branchId: $sale->branch_id, reference: $sale->number);
        });

        return $token;
    }

    /** The sale behind a link, with what its receipt shows; every open is audited. */
    public function open(string $token): ?Sale
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }
        $link = SaleReceiptLink::query()->where('token_hash', hash('sha256', $token))->first();
        if (! $link) {
            return null;
        }

        $sale = Sale::query()->with(TillSaleController::RELATIONS)->findOrFail($link->sale_id);
        $this->audit->log('sales.receipt_link.opened', $sale, after: ['link_id' => $link->id], branchId: $sale->branch_id, reference: $sale->number);

        return $sale;
    }
}
