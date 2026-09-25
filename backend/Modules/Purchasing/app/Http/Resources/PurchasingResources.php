<?php

namespace Modules\Purchasing\Http\Resources;

use Modules\Purchasing\Models\GoodsReceivedNote;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Models\SupplierReturn;
use Modules\Purchasing\Support\Vat;

/**
 * Array shapes for purchasing documents (frontend/types/purchasing.ts). Plain static
 * mappers keep list and detail responses identical without resource-class sprawl.
 */
final class PurchasingResources
{
    /** @return array<string, mixed> */
    public static function supplier(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'kraPin' => $s->kra_pin,
            'contactPerson' => $s->contact_person,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'paymentTermsDays' => $s->payment_terms_days,
            'paymentDetails' => $s->payment_details,
            'notes' => $s->notes,
            'isActive' => $s->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function order(PurchaseOrder $o): array
    {
        $totals = $o->totals();

        return [
            'id' => $o->id,
            'number' => $o->number,
            'status' => $o->status->value,
            'supplier' => ['id' => $o->supplier->id, 'name' => $o->supplier->name],
            'branchId' => $o->branch_id,
            'location' => ['id' => $o->location->id, 'name' => $o->location->name],
            'expectedDate' => $o->expected_date?->toDateString(),
            'note' => $o->note,
            'createdBy' => self::user($o->creator),
            'approvedBy' => self::user($o->approver),
            'approvedAt' => $o->approved_at?->toIso8601String(),
            'sentAt' => $o->sent_at?->toIso8601String(),
            'cancelReason' => $o->cancel_reason,
            'createdAt' => $o->created_at?->toIso8601String(),
            'totals' => ['netCents' => $totals['net'], 'vatCents' => $totals['vat'], 'grossCents' => $totals['gross']],
            'lines' => $o->lines->map(fn ($l) => [
                'id' => $l->id,
                'variant' => ['id' => $l->variant->id, 'displayName' => $l->variant->display_name, 'sku' => $l->variant->sku],
                'quantityOrdered' => $l->quantity_ordered,
                'quantityReceived' => $l->quantity_received,
                'quantityDamaged' => $l->quantity_damaged,
                'outstanding' => $l->outstanding(),
                'unitCostCents' => $l->unit_cost_cents,
                'taxRatePercent' => $l->tax_rate_bp / 100,
                'lineTotalCents' => Vat::line($l->quantity_ordered, $l->unit_cost_cents, $l->tax_rate_bp)['gross'],
            ])->values(),
            'receipts' => $o->relationLoaded('receipts') ? $o->receipts->map(fn ($g) => self::receipt($g))->values() : [],
        ];
    }

    /** @return array<string, mixed> */
    public static function receipt(GoodsReceivedNote $g): array
    {
        return [
            'id' => $g->id,
            'number' => $g->number,
            'purchaseOrderId' => $g->purchase_order_id,
            'purchaseOrderNumber' => $g->relationLoaded('purchaseOrder') ? $g->purchaseOrder->number : null,
            'deliveryNoteRef' => $g->delivery_note_ref,
            'note' => $g->note,
            'receivedBy' => self::user($g->receiver),
            'receivedAt' => $g->created_at?->toIso8601String(),
            'invoiced' => $g->supplier_invoice_id !== null,
            'valueCents' => $g->valueCents(),
            'lines' => $g->lines->map(fn ($l) => [
                'variant' => ['id' => $l->variant->id, 'displayName' => $l->variant->display_name, 'sku' => $l->variant->sku],
                'quantityReceived' => $l->quantity_received,
                'quantityDamaged' => $l->quantity_damaged,
                'batchNumber' => $l->batch_number,
                'expiryDate' => $l->expiry_date?->toDateString(),
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(SupplierInvoice $i): array
    {
        return [
            'id' => $i->id,
            'supplier' => ['id' => $i->supplier->id, 'name' => $i->supplier->name],
            'invoiceNumber' => $i->invoice_number,
            'invoiceDate' => $i->invoice_date->toDateString(),
            'dueDate' => $i->due_date->toDateString(),
            'subtotalCents' => $i->subtotal_cents,
            'vatCents' => $i->vat_cents,
            'totalCents' => $i->total_cents,
            'expectedTotalCents' => $i->expected_total_cents,
            'varianceCents' => $i->variance_cents,
            'matchStatus' => $i->match_status,
            'note' => $i->note,
            'recordedBy' => self::user($i->recorder),
            'receiptNumbers' => $i->receipts->pluck('number')->values(),
            'createdAt' => $i->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function supplierReturn(SupplierReturn $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status->value,
            'supplier' => ['id' => $r->supplier->id, 'name' => $r->supplier->name],
            'location' => ['id' => $r->location->id, 'name' => $r->location->name],
            'reason' => $r->reason,
            'creditNoteRef' => $r->credit_note_ref,
            'requestedBy' => self::user($r->requester),
            'reviewedBy' => self::user($r->reviewer),
            'reviewNote' => $r->review_note,
            'createdAt' => $r->created_at?->toIso8601String(),
            'lines' => $r->lines->map(fn ($l) => [
                'variant' => ['id' => $l->variant->id, 'displayName' => $l->variant->display_name, 'sku' => $l->variant->sku],
                'quantity' => $l->quantity,
            ])->values(),
        ];
    }

    /** @return array{id: int, name: string}|null */
    private static function user($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
