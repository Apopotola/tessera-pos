<?php

namespace Modules\Sales\Services;

use App\Services\DocumentNumberService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\StockEntry;
use Modules\Inventory\Services\StockLedger;
use Modules\Organisation\Models\Location;
use Modules\Sales\Models\OpenBottle;
use Modules\Sales\Models\OpenBottlePour;

/**
 * Sell by tot. Tots pour from the one open bottle of an item at the location; when it
 * runs dry the next bottle is opened (one bottle leaves shelf stock at cost). Pours are
 * append-only, so each bottle shows what it brought in and what was written off.
 */
class OpenBottleService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pours `ml` for a sale, opening bottles as needed. Runs inside the sale's transaction.
     *
     * @return int cost of what was poured, in cents
     */
    public function pour(Location $location, ProductVariant $variant, int $ml, User $user, int $saleId): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('OpenBottleService::pour must run inside a database transaction.');
        }

        $cost = 0;
        while ($ml > 0) {
            $bottle = OpenBottle::query()
                ->where('location_id', $location->id)
                ->where('variant_id', $variant->id)
                ->where('status', OpenBottle::OPEN)
                ->lockForUpdate()
                ->first() ?? $this->open($location, $variant, $user);

            $take = min($ml, $bottle->remainingMl());
            OpenBottlePour::query()->create([
                'open_bottle_id' => $bottle->id,
                'kind' => OpenBottlePour::SALE,
                'ml' => $take,
                'sale_id' => $saleId,
                'user_id' => $user->id,
            ]);

            $bottle->poured_ml += $take;
            if ($bottle->remainingMl() === 0) {
                $bottle->forceFill(['status' => OpenBottle::FINISHED, 'closed_at' => now(), 'closed_by' => $user->id]);
            }
            $bottle->save();

            $cost += $bottle->costOf($take);
            $ml -= $take;
        }

        return $cost;
    }

    /** Pour away what is left (spilt, spoilt, bottle broken) and close the bottle. */
    public function writeOff(OpenBottle $bottle, User $user, string $reason): OpenBottle
    {
        if (! $user->can(Permissions::INVENTORY_ADJUST_APPROVE)) {
            throw new AuthorizationException('Only a manager can write off an open bottle.');
        }

        return DB::transaction(function () use ($bottle, $user, $reason) {
            $bottle = OpenBottle::query()->lockForUpdate()->findOrFail($bottle->id);
            if ($bottle->status !== OpenBottle::OPEN) {
                throw ValidationException::withMessages(['bottle' => 'This bottle is already closed.']);
            }

            $left = $bottle->remainingMl();
            if ($left > 0) {
                OpenBottlePour::query()->create([
                    'open_bottle_id' => $bottle->id,
                    'kind' => OpenBottlePour::WRITE_OFF,
                    'ml' => $left,
                    'user_id' => $user->id,
                    'reason' => $reason,
                ]);
            }

            $bottle->forceFill([
                'poured_ml' => $bottle->volume_ml,
                'status' => OpenBottle::WRITTEN_OFF,
                'closed_at' => now(),
                'closed_by' => $user->id,
            ])->save();

            $this->audit->log('sales.bottle.written_off', $bottle, after: [
                'number' => $bottle->number,
                'ml' => $left,
                'value_cents' => $bottle->costOf($left),
            ], reason: $reason, userId: $user->id, branchId: $bottle->branch_id);

            return $bottle;
        });
    }

    private function open(Location $location, ProductVariant $variant, User $user): OpenBottle
    {
        // Reserve the id so the ledger movement can point at the bottle.
        $id = (int) DB::selectOne("SELECT nextval('open_bottles_id_seq') AS id")->id;
        $number = $this->numbers->next($location->branch, OpenBottle::NUMBER_PREFIX);

        // Like sales, the bar never blocks on stock; counts catch a negative floor.
        [$movement] = $this->ledger->post(
            [new StockEntry($location, $variant->id, -1, MovementType::BottleOpened, reason: 'Opened to sell by the tot')],
            OpenBottle::DOCUMENT_TYPE, $id, $number, $user->id, allowNegative: true,
        );

        $bottle = OpenBottle::query()->forceCreate([
            'id' => $id,
            'number' => $number,
            'branch_id' => $location->branch_id,
            'location_id' => $location->id,
            'variant_id' => $variant->id,
            'volume_ml' => $variant->volume_ml,
            'poured_ml' => 0,
            'unit_cost_cents' => (int) $movement->unit_cost_cents,
            'status' => OpenBottle::OPEN,
            'opened_by' => $user->id,
            'opened_at' => now(),
        ]);

        $this->audit->log('sales.bottle.opened', $bottle, after: ['number' => $number, 'variant_id' => $variant->id], userId: $user->id, branchId: $location->branch_id);

        return $bottle;
    }
}
