<?php

namespace Modules\Organisation\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Till;

/**
 * Pairs devices as tills and resolves a device token back to its till.
 * The plain token is returned once at pairing and never stored.
 */
class TillDeviceService
{
    public const HEADER = 'X-Till-Token';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{branchId: int, name: string, description?: string|null, defaultFloatCents?: int|null}  $data
     * @return array{till: Till, token: string}
     */
    public function pair(array $data, User $manager): array
    {
        return DB::transaction(function () use ($data, $manager) {
            $token = Str::random(64);

            $till = Till::query()->firstOrNew(['branch_id' => $data['branchId'], 'name' => trim($data['name'])]);
            $isNew = ! $till->exists;
            $till->fill([
                'description' => $data['description'] ?? null,
                'default_float_cents' => $data['defaultFloatCents'] ?? 0,
                'is_active' => true,
            ]);
            // Re-pairing an existing till invalidates the old device's token.
            $till->forceFill([
                'device_token_hash' => hash('sha256', $token),
                'paired_at' => now(),
                'paired_by' => $manager->id,
            ])->save();

            $this->audit->log($isNew ? 'organisation.till.created' : 'organisation.till.repaired', $till,
                after: $till->only(['branch_id', 'name', 'description', 'default_float_cents']),
                userId: $manager->id, branchId: $till->branch_id);

            return ['till' => $till, 'token' => $token];
        });
    }

    public function resolve(?string $token): ?Till
    {
        if (! $token || strlen($token) !== 64) {
            return null;
        }

        $till = Till::query()
            ->with('branch.business')
            ->where('device_token_hash', hash('sha256', $token))
            ->where('is_active', true)
            ->first();

        if ($till && (! $till->last_seen_at || $till->last_seen_at->diffInMinutes(now()) >= 5)) {
            $till->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $till?->branch?->is_active ? $till : null;
    }

    /** Stops a lost or replaced device from working. */
    public function unpair(Till $till, User $manager): Till
    {
        return DB::transaction(function () use ($till, $manager) {
            $till->forceFill(['device_token_hash' => null, 'paired_at' => null])->save();
            $this->audit->log('organisation.till.unpaired', $till, userId: $manager->id, branchId: $till->branch_id);

            return $till;
        });
    }

    /**
     * Staff who may sign in at this till: active, PIN set, allowed to sell, and assigned to the branch.
     *
     * @return Collection<int, User>
     */
    public function cashiersFor(Till $till): Collection
    {
        return User::query()
            ->permission(Permissions::SALES_SELL)
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->where(function ($q) use ($till) {
                $q->whereHas('branches', fn ($b) => $b->whereKey($till->branch_id))
                    ->orWhere(fn ($all) => $all->permission(Permissions::ORGANISATION_ALL_BRANCHES));
            })
            ->with('roles')
            ->orderBy('name')
            ->get();
    }
}
