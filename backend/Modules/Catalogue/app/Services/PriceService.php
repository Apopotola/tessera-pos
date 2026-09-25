<?php

namespace Modules\Catalogue\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Enums\PriceStatus;
use Modules\Catalogue\Enums\PriceTier;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\VariantPrice;

/**
 * Maker–checker price changes. A user holding `catalogue.prices.approve` (Owner)
 * applies prices immediately; anyone else creates a pending request that a
 * different approver must review.
 */
class PriceService
{
    public function __construct(
        private readonly PriceResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{tier: string, branchId?: int|null, priceCents: int, minPriceCents?: int|null, effectiveFrom?: string|null, reason?: string|null}  $data
     * @return array{price: VariantPrice, warnings: list<string>}
     */
    public function request(ProductVariant $variant, array $data, User $user): array
    {
        $tier = PriceTier::from($data['tier']);
        $branchId = $data['branchId'] ?? null;
        $priceCents = (int) $data['priceCents'];
        $now = CarbonImmutable::now();
        $effectiveFrom = isset($data['effectiveFrom']) ? CarbonImmutable::parse($data['effectiveFrom']) : $now;
        $autoApprove = $user->can(Permissions::PRICES_APPROVE);

        if ($tier === PriceTier::Tot && ! $variant->tot_ml) {
            throw ValidationException::withMessages(['tier' => 'Set a tot size on this item before giving it a tot price.']);
        }

        $warnings = $this->ladderWarnings($variant, $tier, $branchId, $priceCents);

        $price = DB::transaction(function () use ($variant, $data, $tier, $branchId, $priceCents, $effectiveFrom, $autoApprove, $user, $now) {
            $price = VariantPrice::query()->create([
                'variant_id' => $variant->id,
                'branch_id' => $branchId,
                'tier' => $tier,
                'price_cents' => $priceCents,
                'min_price_cents' => $data['minPriceCents'] ?? null,
                'effective_from' => $effectiveFrom,
                'status' => $autoApprove ? PriceStatus::Approved : PriceStatus::Pending,
                'reason' => $data['reason'] ?? null,
                'requested_by' => $user->id,
                'reviewed_by' => $autoApprove ? $user->id : null,
                'reviewed_at' => $autoApprove ? $now : null,
                'review_note' => $autoApprove ? 'Applied directly by a price approver.' : null,
            ]);

            $this->audit->log(
                $autoApprove ? 'catalogue.price.applied' : 'catalogue.price.requested',
                $price,
                after: $this->snapshot($price),
                reason: $price->reason,
                branchId: $branchId,
            );

            return $price;
        });

        return ['price' => $price, 'warnings' => $warnings];
    }

    public function approve(VariantPrice $price, User $approver, ?string $note = null): VariantPrice
    {
        $this->assertReviewable($price, $approver);

        return DB::transaction(function () use ($price, $approver, $note) {
            $now = CarbonImmutable::now();
            $before = $this->snapshot($price);

            $price->forceFill([
                'status' => PriceStatus::Approved,
                'reviewed_by' => $approver->id,
                'reviewed_at' => $now,
                'review_note' => $note,
                // A request approved after its requested start date takes effect from approval.
                'effective_from' => $price->effective_from->greaterThan($now) ? $price->effective_from : $now,
            ])->save();

            $this->audit->log('catalogue.price.approved', $price, $before, $this->snapshot($price), $note, approverId: $approver->id, branchId: $price->branch_id);

            return $price;
        });
    }

    public function reject(VariantPrice $price, User $approver, string $note): VariantPrice
    {
        $this->assertReviewable($price, $approver);

        return DB::transaction(function () use ($price, $approver, $note) {
            $before = $this->snapshot($price);

            $price->forceFill([
                'status' => PriceStatus::Rejected,
                'reviewed_by' => $approver->id,
                'reviewed_at' => CarbonImmutable::now(),
                'review_note' => $note,
            ])->save();

            $this->audit->log('catalogue.price.rejected', $price, $before, $this->snapshot($price), $note, approverId: $approver->id, branchId: $price->branch_id);

            return $price;
        });
    }

    /**
     * Soft check from the requirements: a bigger pack should not cost less than a
     * smaller sibling of the same product and container, and vice versa.
     *
     * @return list<string>
     */
    public function ladderWarnings(ProductVariant $variant, PriceTier $tier, ?int $branchId, int $priceCents): array
    {
        if ($tier === PriceTier::Tot) {
            return $this->totWarnings($variant, $branchId, $priceCents);
        }

        $siblings = ProductVariant::query()
            ->where('product_id', $variant->product_id)
            ->where('container', $variant->container)
            ->whereKeyNot($variant->id)
            ->where('is_active', true)
            ->get();

        $current = $this->resolver->currentForVariants($siblings->modelKeys(), $branchId);
        $warnings = [];

        foreach ($siblings as $sibling) {
            $siblingPrice = $current[$sibling->id][$tier->value] ?? null;
            if (! $siblingPrice) {
                continue;
            }

            if ($sibling->volume_ml < $variant->volume_ml && $priceCents < $siblingPrice->price_cents) {
                $warnings[] = "{$variant->volume_label} would cost less than the {$sibling->volume_label} size.";
            }

            if ($sibling->volume_ml > $variant->volume_ml && $priceCents > $siblingPrice->price_cents) {
                $warnings[] = "{$variant->volume_label} would cost more than the {$sibling->volume_label} size.";
            }
        }

        return $warnings;
    }

    /**
     * A bottle poured as tots should bring in at least what it sells for whole.
     *
     * @return list<string>
     */
    private function totWarnings(ProductVariant $variant, ?int $branchId, int $totPriceCents): array
    {
        $bottle = $this->resolver->current($variant->id, $branchId, PriceTier::Retail);
        if (! $bottle || ! $variant->tot_ml) {
            return [];
        }

        $tots = intdiv($variant->volume_ml, $variant->tot_ml);
        if ($tots * $totPriceCents < $bottle->price_cents) {
            return ["{$tots} tots at this price bring in less than the bottle's retail price."];
        }

        return [];
    }

    private function assertReviewable(VariantPrice $price, User $approver): void
    {
        if (! $approver->can(Permissions::PRICES_APPROVE)) {
            throw new AuthorizationException;
        }

        if ($price->requested_by === $approver->id) {
            throw new AuthorizationException('You cannot review your own price request.');
        }

        if ($price->status !== PriceStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Only pending price requests can be reviewed.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(VariantPrice $price): array
    {
        return [
            'variant_id' => $price->variant_id,
            'branch_id' => $price->branch_id,
            'tier' => $price->tier->value,
            'price_cents' => $price->price_cents,
            'min_price_cents' => $price->min_price_cents,
            'effective_from' => $price->effective_from->toIso8601String(),
            'status' => $price->status->value,
        ];
    }
}
