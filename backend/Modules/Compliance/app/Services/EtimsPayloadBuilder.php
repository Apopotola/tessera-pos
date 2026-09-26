<?php

namespace Modules\Compliance\Services;

use Modules\Catalogue\Models\ProductVariant;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleReturn;

/**
 * Turns a sale or return into an eTIMS invoice / credit note. Field names follow the
 * OSCU/VSCU style (invcNo, itemList, taxblAmtB…) and REQUIRE VALIDATION against the
 * KRA v2.0 spec before certification. Prices are VAT-inclusive; amounts in KES.
 * Tax type codes A–E are the catalogue tax-rate codes (A exempt, B 16%, C zero, D non-VAT, E 8%).
 */
class EtimsPayloadBuilder
{
    private const TAX_TYPES = ['A', 'B', 'C', 'D', 'E'];

    /** @return array<string, mixed> */
    public function forSale(Sale $sale): array
    {
        $sale->loadMissing(['lines.variant.taxRate', 'lines.variant.product', 'branch.business', 'tenders']);

        $items = $sale->lines->values()->map(fn ($line, $i) => $this->item(
            $i + 1, $line->variant, $line->quantity, $line->unit_price_cents, $line->discount_cents + (int) $line->promotion_discount_cents, $line->line_total_cents, $line->vat_cents,
            $line->unit === 'tot' ? " (tot {$line->tot_ml}ml)" : '',
        ))->all();

        return $this->document($sale->branch->business->kra_pin, $sale->number, null, 'S', $sale->customer_pin, $sale->completed_at->format('YmdHis'), $items, $this->paymentType($sale));
    }

    /** @return array<string, mixed> */
    public function forCreditNote(SaleReturn $return, ?string $originalKraInvoice): array
    {
        $return->loadMissing(['lines.variant.taxRate', 'lines.variant.product', 'lines.saleLine', 'sale.branch.business']);

        $items = $return->lines->values()->map(function ($line, $i) {
            $saleLine = $line->saleLine;
            $vat = intdiv($saleLine->vat_cents * $line->quantity * 2 + $saleLine->quantity, 2 * $saleLine->quantity);
            $discount = $line->quantity * $saleLine->unit_price_cents - $line->amount_cents;

            return $this->item($i + 1, $line->variant, $line->quantity, $saleLine->unit_price_cents, max(0, $discount), $line->amount_cents, $vat, '');
        })->all();

        return $this->document($return->sale->branch->business->kra_pin, $return->number, $originalKraInvoice, 'R', $return->sale->customer_pin, $return->created_at->format('YmdHis'), $items, '01');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function document(?string $tin, string $number, ?string $original, string $receiptType, ?string $buyerPin, string $date, array $items, string $paymentType): array
    {
        $bands = [];
        foreach (self::TAX_TYPES as $type) {
            $inBand = array_filter($items, fn ($item) => $item['taxTyCd'] === $type);
            $bands["taxblAmt{$type}"] = round(array_sum(array_column($inBand, 'taxblAmt')), 2);
            $bands["taxAmt{$type}"] = round(array_sum(array_column($inBand, 'taxAmt')), 2);
        }

        return [
            'tin' => $tin,
            'bhfId' => config('compliance.etims.branch_code', '00'),
            'invcNo' => $number,
            'orgInvcNo' => $original,
            'custTin' => $buyerPin,
            'salesTyCd' => 'N',          // normal (not training / proforma)
            'rcptTyCd' => $receiptType,  // S sale, R credit note (refund)
            'pmtTyCd' => $paymentType,
            'salesDt' => substr($date, 0, 8),
            'cfmDt' => $date,
            'totItemCnt' => count($items),
            ...$bands,
            'totTaxblAmt' => round(array_sum(array_column($items, 'taxblAmt')), 2),
            'totTaxAmt' => round(array_sum(array_column($items, 'taxAmt')), 2),
            'totAmt' => round(array_sum(array_column($items, 'totAmt')), 2),
            'itemList' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function item(int $seq, ProductVariant $variant, int $qty, int $unitPrice, int $discount, int $total, int $vat, string $suffix): array
    {
        return [
            'itemSeq' => $seq,
            'itemClsCd' => $variant->etims_item_class_code,
            'itemCd' => $variant->etims_item_code ?? $variant->sku,
            'itemNm' => $variant->display_name.$suffix,
            'qty' => $qty,
            'prc' => $unitPrice / 100,
            'splyAmt' => round($qty * $unitPrice / 100, 2),
            'dcAmt' => $discount / 100,
            'taxTyCd' => in_array($variant->taxRate->code, self::TAX_TYPES, true) ? $variant->taxRate->code : 'B',
            'taxblAmt' => $total / 100,
            'taxAmt' => $vat / 100,
            'totAmt' => $total / 100,
        ];
    }

    /** eTIMS payment type codes (REQUIRES VALIDATION): 01 cash, 02 credit, 06 mobile money, 05 card, 07 mixed. */
    private function paymentType(Sale $sale): string
    {
        $methods = $sale->tenders->where('amount_cents', '>', 0)->pluck('method')->unique();

        return match (true) {
            $methods->count() > 1 => '07',
            $methods->first() === 'mpesa' => '06',
            $methods->first() === 'card' => '05',
            $methods->first() === 'credit' => '02',
            default => '01',
        };
    }
}
