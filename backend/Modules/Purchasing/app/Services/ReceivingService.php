<?php

namespace Modules\Purchasing\Services;

use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryGuard;
use Modules\Inventory\Services\StockEntry;
use Modules\Inventory\Services\StockLedger;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Models\GoodsReceivedNote;
use Modules\Purchasing\Models\PurchaseOrder;

/**
 * Goods received against an approved purchase order. Good units post straight to the
 * stock ledger at the PO cost (updating the weighted average); damaged-on-arrival units
 * are recorded but never enter stock — the supplier owes a credit for them.
 */
class ReceivingService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryGuard $guard,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{lineId: int, received: int, damaged?: int, batchNumber?: string|null, expiryDate?: string|null}>  $lines
     */
    public function receive(PurchaseOrder $order, User $user, array $lines, ?string $deliveryNoteRef, ?string $note): GoodsReceivedNote
    {
        $this->guard->requireAny($user, [Permissions::INVENTORY_RECEIVE]);
        $this->guard->requireBranch($user, $order->branch_id);

        return DB::transaction(function () use ($order, $user, $lines, $deliveryNoteRef, $note) {
            $order = PurchaseOrder::query()->with(['lines', 'location', 'branch'])->lockForUpdate()->findOrFail($order->id);

            if (! $order->status->canReceive()) {
                throw ValidationException::withMessages(['status' => "Goods can only be received against an approved order (this one is {$order->status->value})."]);
            }

            $grn = GoodsReceivedNote::query()->create([
                'number' => $this->numbers->next($order->branch, GoodsReceivedNote::NUMBER_PREFIX),
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'branch_id' => $order->branch_id,
                'location_id' => $order->location_id,
                'delivery_note_ref' => $deliveryNoteRef,
                'note' => $note,
                'received_by' => $user->id,
            ]);

            $entries = [];
            foreach ($lines as $i => $input) {
                $line = $order->lines->firstWhere('id', $input['lineId'])
                    ?? throw ValidationException::withMessages(["lines.{$i}.lineId" => 'This line is not on the order.']);
                $received = (int) $input['received'];
                $damaged = (int) ($input['damaged'] ?? 0);

                if ($received + $damaged === 0) {
                    continue;
                }
                if ($received + $damaged > $line->outstanding()) {
                    throw ValidationException::withMessages(["lines.{$i}.received" => "Only {$line->outstanding()} still expected on this line."]);
                }

                $grn->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'variant_id' => $line->variant_id,
                    'quantity_received' => $received,
                    'quantity_damaged' => $damaged,
                    'unit_cost_cents' => $line->unit_cost_cents,
                    'tax_rate_bp' => $line->tax_rate_bp,
                    'batch_number' => $input['batchNumber'] ?? null,
                    'expiry_date' => $input['expiryDate'] ?? null,
                ]);
                $line->increment('quantity_received', $received);
                $line->increment('quantity_damaged', $damaged);

                if ($received > 0) {
                    $entries[] = new StockEntry($order->location, $line->variant_id, $received, MovementType::GoodsReceived, $line->unit_cost_cents, "From {$order->number}");
                    $this->rememberCost($order->supplier_id, $line->variant_id, $line->unit_cost_cents);
                }
            }

            if ($grn->lines()->doesntExist()) {
                throw ValidationException::withMessages(['lines' => 'Enter at least one quantity received.']);
            }

            $this->ledger->post($entries, GoodsReceivedNote::DOCUMENT_TYPE, $grn->id, $grn->number, $user->id, $order->approved_by);

            $complete = $order->lines->every(fn ($l) => $l->refresh()->outstanding() === 0);
            $order->forceFill([
                'status' => $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived,
                'closed_at' => $complete ? now() : null,
            ])->save();

            $this->audit->log('purchasing.grn.received', $grn, after: ['number' => $grn->number, 'po' => $order->number, 'lines' => $lines],
                reason: $note, userId: $user->id, branchId: $order->branch_id);

            return $grn;
        });
    }

    private function rememberCost(int $supplierId, int $variantId, int $costCents): void
    {
        DB::table('supplier_items')->upsert(
            [['supplier_id' => $supplierId, 'variant_id' => $variantId, 'last_cost_cents' => $costCents, 'last_purchased_at' => now()]],
            ['supplier_id', 'variant_id'],
            ['last_cost_cents', 'last_purchased_at'],
        );
    }
}
