<?php

namespace Modules\Dashboard\Services;

use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Enums\PriceStatus;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\BranchAccessService;
use Modules\Sales\Models\Shift;

/**
 * Today's operational snapshot, built only from data that exists.
 * Each section is null when the user lacks the permission to see it.
 * Sales, stock and eTIMS KPIs are added here as those modules land.
 */
class DashboardService
{
    public function __construct(private readonly BranchAccessService $branchAccess) {}

    /** @return array<string, mixed> */
    public function summaryFor(User $user): array
    {
        $branches = $this->branchAccess->branchesFor($user);
        $branchIds = $branches->modelKeys();

        return [
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->values(),

            'catalogue' => $user->can(Permissions::CATALOGUE_VIEW) ? [
                'activeProducts' => Product::query()->where('is_active', true)->count(),
                'activeVariants' => ProductVariant::query()->where('is_active', true)->count(),
            ] : null,

            'pendingPriceChanges' => $user->canAny([Permissions::PRICES_MANAGE, Permissions::PRICES_APPROVE])
                ? VariantPrice::query()->where('status', PriceStatus::Pending)->count()
                : null,

            'tills' => $user->can(Permissions::ORGANISATION_MANAGE) || $user->can(Permissions::SHIFTS_CASHUP_APPROVE) ? [
                'total' => Till::query()->whereIn('branch_id', $branchIds)->where('is_active', true)->count(),
                'connected' => Till::query()->whereIn('branch_id', $branchIds)->where('is_active', true)->whereNotNull('paired_at')->count(),
            ] : null,

            'openShifts' => $user->can(Permissions::SHIFTS_CASHUP_APPROVE)
                ? Shift::query()
                    ->with(['user:id,name', 'till:id,name', 'branch:id,code'])
                    ->whereIn('branch_id', $branchIds)
                    ->whereNull('closed_at')
                    ->orderBy('opened_at')
                    ->get()
                    ->map(fn (Shift $s) => [
                        'id' => $s->id,
                        'cashier' => $s->user->name,
                        'till' => $s->till->name,
                        'branchCode' => $s->branch->code,
                        'openedAt' => $s->opened_at->toIso8601String(),
                        'openingFloatCents' => $s->opening_float_cents,
                    ])->values()
                : null,

            'staff' => $user->can(Permissions::USERS_MANAGE) ? [
                'active' => User::query()->where('is_active', true)->count(),
                'cashiersWithoutPin' => User::permission(Permissions::SALES_SELL)->where('is_active', true)->whereNull('pin_hash')->count(),
            ] : null,
        ];
    }
}
