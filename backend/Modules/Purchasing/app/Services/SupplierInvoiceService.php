<?php

namespace Modules\Purchasing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Services\InventoryGuard;
use Modules\Purchasing\Models\GoodsReceivedNote;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierInvoice;

/**
 * Three-way match: the supplier's invoice total is compared with the value of the
 * goods actually received (GRNs at PO cost + VAT). Differences above the tolerance
 * are flagged for querying with the supplier before payment.
 */
class SupplierInvoiceService
{
    /** Rounding tolerance before a difference counts as a variance: KES 1. */
    public const TOLERANCE_CENTS = 100;

    public function __construct(private readonly InventoryGuard $guard, private readonly AuditLogger $audit) {}

    /** @param array{supplierId: int, invoiceNumber: string, invoiceDate: string, subtotalCents: int, vatCents: int, totalCents: int, grnIds: list<int>, note?: string|null} $data */
    public function record(array $data, User $user): SupplierInvoice
    {
        $this->guard->requireAny($user, [Permissions::PURCHASING_MANAGE]);
        $supplier = Supplier::query()->findOrFail($data['supplierId']);

        if ($data['totalCents'] !== $data['subtotalCents'] + $data['vatCents']) {
            throw ValidationException::withMessages(['totalCents' => 'Subtotal plus VAT must equal the invoice total.']);
        }

        return DB::transaction(function () use ($data, $user, $supplier) {
            $grns = GoodsReceivedNote::query()->with('lines')->whereIn('id', $data['grnIds'])->lockForUpdate()->get();

            foreach ($grns as $grn) {
                if ($grn->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['grnIds' => "{$grn->number} is from a different supplier."]);
                }
                if ($grn->supplier_invoice_id !== null) {
                    throw ValidationException::withMessages(['grnIds' => "{$grn->number} is already on another invoice."]);
                }
                $this->guard->requireBranch($user, $grn->branch_id);
            }

            $expected = $grns->sum(fn (GoodsReceivedNote $g) => $g->valueCents());
            $variance = $data['totalCents'] - $expected;
            $invoiceDate = CarbonImmutable::parse($data['invoiceDate']);

            $invoice = SupplierInvoice::query()->create([
                'supplier_id' => $supplier->id,
                'invoice_number' => trim($data['invoiceNumber']),
                'invoice_date' => $invoiceDate,
                'due_date' => $invoiceDate->addDays($supplier->payment_terms_days),
                'subtotal_cents' => $data['subtotalCents'],
                'vat_cents' => $data['vatCents'],
                'total_cents' => $data['totalCents'],
                'expected_total_cents' => $expected,
                'variance_cents' => $variance,
                'match_status' => abs($variance) <= self::TOLERANCE_CENTS ? SupplierInvoice::MATCHED : SupplierInvoice::VARIANCE,
                'note' => $data['note'] ?? null,
                'recorded_by' => $user->id,
            ]);

            GoodsReceivedNote::query()->whereIn('id', $grns->modelKeys())->update(['supplier_invoice_id' => $invoice->id]);

            $this->audit->log('purchasing.invoice.recorded', $invoice, after: [
                'invoice_number' => $invoice->invoice_number,
                'total_cents' => $invoice->total_cents,
                'expected_total_cents' => $expected,
                'match_status' => $invoice->match_status,
            ], userId: $user->id);

            return $invoice;
        });
    }
}
