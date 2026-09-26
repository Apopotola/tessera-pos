<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Authorization\Support\Permissions;
use Modules\Sales\Models\Sale;
use Modules\Sales\Services\TillPolicy;

/**
 * A sale as a receipt and back-office record. Cost and margin only with reports.profit.view.
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $showCost = $request->user()?->can(Permissions::REPORTS_PROFIT_VIEW) ?? false;
        $returned = $this->returns->sum('total_cents');

        return [
            'id' => $this->id,
            'number' => $this->number,
            'capturedOffline' => (bool) $this->captured_offline,
            'completedAt' => $this->completed_at->toIso8601String(),
            'status' => $this->status,
            'etimsStatus' => $this->etims_status,
            // KRA details for the receipt once signed (null while pending).
            'etims' => $this->whenLoaded('etimsSubmission', fn () => $this->etimsSubmission?->receiptArray()),
            'business' => ['name' => $this->branch->business->name, 'kraPin' => $this->branch->business->kra_pin],
            'branch' => ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name],
            'till' => ['id' => $this->till->id, 'name' => $this->till->name],
            'cashier' => ['id' => $this->cashier->id, 'name' => $this->cashier->name],
            'customerPin' => $this->customer_pin,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name, 'isWholesale' => $this->customer->is_wholesale] : null),
            'subtotalCents' => $this->subtotal_cents,
            'discountCents' => $this->discount_cents,
            'totalCents' => $this->total_cents,
            // Cash rounding: cash taken minus cash due (the total and VAT stay exact).
            'roundingCents' => (int) $this->rounding_cents,
            'vatCents' => $this->vat_cents,
            'returnedCents' => $returned,
            'costCents' => $showCost ? $this->cost_cents : null,
            'grossProfitCents' => $showCost ? $this->total_cents - $this->vat_cents - $this->cost_cents : null,
            'lines' => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'variant' => ['id' => $l->variant->id, 'displayName' => $l->variant->display_name, 'sku' => $l->variant->sku],
                'unit' => $l->unit,
                'totMl' => $l->tot_ml,
                'quantity' => $l->quantity,
                'listPriceCents' => $l->list_price_cents,
                'unitPriceCents' => $l->unit_price_cents,
                'discountCents' => $l->discount_cents,
                'lineTotalCents' => $l->line_total_cents,
                'vatCents' => $l->vat_cents,
                'taxRatePercent' => $l->tax_rate_bp / 100,
                'returnedQuantity' => $l->returned_quantity,
                'approvedBy' => $l->approver ? ['id' => $l->approver->id, 'name' => $l->approver->name] : null,
            ])->values(),
            'tenders' => $this->tenders->map(fn ($t) => [
                'method' => $t->method,
                'amountCents' => $t->amount_cents,
                'tenderedCents' => $t->tendered_cents,
                'changeCents' => $t->change_cents,
                'reference' => $t->reference,
                'cardLast4' => $t->card_last4,
                // Matched later from the reconciliation screen counts as confirmed too.
                'status' => $t->relationLoaded('confirmation') && $t->confirmation ? 'confirmed' : $t->status,
            ])->values(),
            'returns' => $this->returns->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->number,
                'totalCents' => $r->total_cents,
                'reason' => $r->reason,
                'etimsStatus' => $r->etims_status,
                'createdAt' => $r->created_at?->toIso8601String(),
            ])->values(),
            // Receipt layout for this branch and till (Settings → Receipts), so reprints match.
            'receipt' => app(TillPolicy::class)->receipt($this->branch_id, $this->till_id),
        ];
    }
}
