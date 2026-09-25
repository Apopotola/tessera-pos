<?php

namespace Modules\Inventory\Services;

use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\CountStatus;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\StockCount;
use Modules\Organisation\Enums\LocationType;
use Modules\Organisation\Models\Location;

/**
 * Blind stock counts. Counters never see the expected quantity; it is frozen at
 * submit and the variance posts only when a different person approves.
 */
class CountService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryGuard $guard,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{locationId: int, note?: string|null, variantIds?: list<int>} $data */
    public function create(array $data, User $user): StockCount
    {
        $this->guard->requireAny($user, [Permissions::INVENTORY_COUNT]);
        $location = Location::query()->with('branch')->findOrFail($data['locationId']);
        $this->guard->requireBranch($user, $location->branch_id);

        if ($location->type === LocationType::Transit) {
            throw ValidationException::withMessages(['locationId' => 'Stock in transit cannot be counted.']);
        }

        $variantIds = DB::table('stock_balances')
            ->where('location_id', $location->id)
            ->where('quantity', '!=', 0)
            ->pluck('variant_id')
            ->merge($data['variantIds'] ?? [])
            ->unique()
            ->values();

        if ($variantIds->isEmpty()) {
            throw ValidationException::withMessages(['variantIds' => 'Nothing is recorded at this location — add the items you want to count.']);
        }

        return DB::transaction(function () use ($data, $location, $user, $variantIds) {
            $count = StockCount::query()->create([
                'number' => $this->numbers->next($location->branch, StockCount::NUMBER_PREFIX),
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'status' => CountStatus::Counting,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);
            $count->lines()->createMany($variantIds->map(fn ($id) => ['variant_id' => $id])->all());

            $this->audit->log('inventory.count.started', $count, after: ['number' => $count->number, 'lines' => $variantIds->count()],
                userId: $user->id, branchId: $count->branch_id);

            return $count;
        });
    }

    /** @param list<array{variantId: int, countedQuantity: int|null}> $lines */
    public function record(StockCount $count, User $user, array $lines): StockCount
    {
        $this->guard->requireAny($user, [Permissions::INVENTORY_COUNT]);
        $this->guard->requireBranch($user, $count->branch_id);
        $this->guard->requireStatus($count->status, CountStatus::Counting);

        return DB::transaction(function () use ($count, $lines) {
            foreach ($lines as $line) {
                // Items found on the shelf that were not on the sheet are added.
                $count->lines()->updateOrCreate(['variant_id' => $line['variantId']], ['counted_quantity' => $line['countedQuantity']]);
            }

            return $count;
        });
    }

    public function submit(StockCount $count, User $user): StockCount
    {
        $this->guard->requireAny($user, [Permissions::INVENTORY_COUNT]);
        $this->guard->requireBranch($user, $count->branch_id);

        return DB::transaction(function () use ($count, $user) {
            $count = StockCount::query()->with(['lines', 'location'])->lockForUpdate()->findOrFail($count->id);
            $this->guard->requireStatus($count->status, CountStatus::Counting);

            if ($count->lines->contains(fn ($l) => $l->counted_quantity === null)) {
                throw ValidationException::withMessages(['lines' => 'Enter a count for every line (0 if none) before submitting.']);
            }

            foreach ($count->lines as $line) {
                $expected = $this->ledger->onHand($count->location, $line->variant_id);
                $line->update([
                    'expected_quantity' => $expected,
                    'variance' => $line->counted_quantity - $expected,
                    'unit_cost_cents' => $this->ledger->averageCost($count->branch_id, $line->variant_id),
                ]);
            }

            $count->forceFill(['status' => CountStatus::Submitted, 'submitted_by' => $user->id, 'submitted_at' => now()])->save();
            $this->audit->log('inventory.count.submitted', $count, after: [
                'variance_units' => $count->lines->sum('variance'),
            ], userId: $user->id, branchId: $count->branch_id);

            return $count;
        });
    }

    public function approve(StockCount $count, User $approver, ?string $note = null): StockCount
    {
        $this->guard->requireAny($approver, [Permissions::INVENTORY_COUNT_APPROVE]);
        $this->guard->requireBranch($approver, $count->branch_id);
        $this->guard->requireDifferentPerson($count->submitted_by, $approver);

        return DB::transaction(function () use ($count, $approver, $note) {
            $count = StockCount::query()->with(['lines', 'location'])->lockForUpdate()->findOrFail($count->id);
            $this->guard->requireStatus($count->status, CountStatus::Submitted);

            $entries = $count->lines
                ->filter(fn ($l) => $l->variance !== 0)
                ->map(fn ($l) => new StockEntry($count->location, $l->variant_id, $l->variance, MovementType::CountVariance, reason: "Count {$count->number}"))
                ->values()
                ->all();

            // Variances are relative to the frozen snapshot; sales since submit may take a line below zero.
            $this->ledger->post($entries, StockCount::DOCUMENT_TYPE, $count->id, $count->number, (int) $count->submitted_by, $approver->id, allowNegative: true);

            $count->forceFill(['status' => CountStatus::Approved, 'reviewed_by' => $approver->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->audit->log('inventory.count.approved', $count, reason: $note, approverId: $approver->id, branchId: $count->branch_id);

            return $count;
        });
    }

    public function reject(StockCount $count, User $approver, string $note): StockCount
    {
        $this->guard->requireAny($approver, [Permissions::INVENTORY_COUNT_APPROVE]);
        $this->guard->requireBranch($approver, $count->branch_id);
        $this->guard->requireStatus($count->status, CountStatus::Submitted);

        return DB::transaction(function () use ($count, $approver, $note) {
            $count->forceFill(['status' => CountStatus::Rejected, 'reviewed_by' => $approver->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->audit->log('inventory.count.rejected', $count, reason: $note, approverId: $approver->id, branchId: $count->branch_id);

            return $count;
        });
    }
}
