<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Models\Sale;
use Modules\Sales\Services\TillPolicy;

/**
 * A receipt as the customer sees it behind a shared link: what the paper receipt shows and nothing
 * more. No database ids, costs, margins, approvers, return reasons or payment references; the
 * cashier's name only when receipts show it (Settings → Receipts).
 *
 * @mixin Sale
 */
class PublicReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $receipt = app(TillPolicy::class)->receipt($this->branch_id, $this->till_id);
        $show = fn (string $what) => in_array($what, $receipt['show'], true);

        return [
            'number' => $this->number,
            'completedAt' => $this->completed_at->toIso8601String(),
            'status' => $this->status,
            'etimsStatus' => $this->etims_status,
            'etims' => $this->etimsSubmission?->receiptArray(),
            'business' => ['name' => $this->branch->business->name, 'kraPin' => $this->branch->business->kra_pin],
            'branch' => ['name' => $this->branch->name],
            'till' => ['name' => $this->till->name],
            'cashier' => $show('cashier') ? ['name' => $this->cashier->name] : null,
            'customer' => $show('customer') && $this->customer ? ['name' => $this->customer->name] : null,
            'customerPin' => $this->customer_pin,
            'subtotalCents' => $this->subtotal_cents,
            'discountCents' => $this->discount_cents,
            'totalCents' => $this->total_cents,
            'roundingCents' => (int) $this->rounding_cents,
            'vatCents' => $this->vat_cents,
            'returnedCents' => (int) $this->returns->sum('total_cents'),
            'lines' => $this->lines->map(fn ($l) => [
                'displayName' => $l->variant->display_name,
                'unit' => $l->unit,
                'totMl' => $l->tot_ml,
                'quantity' => $l->quantity,
                'unitPriceCents' => $l->unit_price_cents,
                'discountCents' => $l->discount_cents,
                'promotionDiscountCents' => (int) $l->promotion_discount_cents,
                'promotionName' => $l->promotion?->name,
                'lineTotalCents' => $l->line_total_cents,
            ])->values(),
            'tenders' => $this->tenders->where('amount_cents', '>', 0)->map(fn ($t) => [
                'method' => $t->method,
                'amountCents' => $t->amount_cents,
                'tenderedCents' => $t->tendered_cents,
                'changeCents' => $t->change_cents,
            ])->values(),
            'receipt' => [
                'headerLines' => $receipt['headerLines'],
                'footerLines' => $receipt['footerLines'],
                'licenceLines' => $receipt['licenceLines'],
                'logo' => $show('logo') ? $receipt['logo'] : null,
                'returnPolicyDays' => $show('return_policy') ? $receipt['returnWindowDays'] : null,
            ],
        ];
    }
}
