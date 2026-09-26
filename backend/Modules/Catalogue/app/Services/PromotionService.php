<?php

namespace Modules\Catalogue\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Models\Promotion;
use Modules\Catalogue\Models\PromotionTarget;
use Modules\Catalogue\Support\PromotionEngine;

/**
 * Promotions: set up by a manager, approved by the owner (maker–checker), ended early if needed.
 * An approved promotion is never edited: end it and set up a new one.
 */
class PromotionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data validated payload */
    public function request(array $data, User $user): Promotion
    {
        if (! $user->can(Permissions::PROMOTIONS_REQUEST)) {
            throw new AuthorizationException;
        }

        // As with price changes, the owner's own promotion is approved as it is set up.
        $autoApprove = $user->can(Permissions::PROMOTIONS_APPROVE);

        return DB::transaction(function () use ($data, $user, $autoApprove) {
            $promotion = Promotion::query()->create([
                'name' => trim($data['name']),
                'status' => $autoApprove ? Promotion::ACTIVE : Promotion::PENDING,
                'discount_type' => $data['discountType'],
                'discount_value' => (int) $data['discountValue'],
                'min_quantity' => (int) ($data['minQuantity'] ?? 1),
                'unit' => $data['unit'] ?? 'any',
                'starts_on' => $data['startsOn'],
                'ends_on' => $data['endsOn'],
                'weekdays' => ! empty($data['weekdays']) ? array_values(array_map('intval', $data['weekdays'])) : null,
                'time_from' => $data['timeFrom'] ?? null,
                'time_to' => $data['timeTo'] ?? null,
                'branch_ids' => ! empty($data['branchIds']) ? array_values(array_map('intval', $data['branchIds'])) : null,
                'requested_by' => $user->id,
            ]);
            foreach (['category' => 'categoryIds', 'brand' => 'brandIds', 'variant' => 'variantIds'] as $type => $key) {
                foreach (array_unique($data[$key] ?? []) as $id) {
                    PromotionTarget::query()->create(['promotion_id' => $promotion->id, 'target_type' => $type, 'target_id' => (int) $id]);
                }
            }
            if ($autoApprove) {
                $promotion->forceFill(['reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => 'Set up directly by a promotion approver.'])->save();
            }
            $this->audit->log($autoApprove ? 'catalogue.promotion.approved' : 'catalogue.promotion.requested', $promotion, after: $promotion->load('targets')->rule(), userId: $user->id);

            return $promotion;
        });
    }

    public function approve(Promotion $promotion, User $approver, ?string $note): Promotion
    {
        $this->assertReviewable($promotion, $approver);
        if ($promotion->ends_on->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['promotion' => 'This promotion has already finished. Set up a new one.']);
        }

        return $this->review($promotion, $approver, Promotion::ACTIVE, $note, 'catalogue.promotion.approved');
    }

    public function reject(Promotion $promotion, User $approver, string $note): Promotion
    {
        $this->assertReviewable($promotion, $approver);

        return $this->review($promotion, $approver, Promotion::REJECTED, $note, 'catalogue.promotion.rejected');
    }

    /** Stop an approved promotion now (sales already made keep their discount). */
    public function end(Promotion $promotion, User $user, string $reason): Promotion
    {
        if (! $user->can(Permissions::PROMOTIONS_APPROVE)) {
            throw new AuthorizationException;
        }
        if ($promotion->status !== Promotion::ACTIVE) {
            throw ValidationException::withMessages(['promotion' => 'Only a running promotion can be ended.']);
        }

        return DB::transaction(function () use ($promotion, $user, $reason) {
            $promotion->forceFill(['status' => Promotion::ENDED, 'ended_by' => $user->id, 'ended_at' => now()])->save();
            $this->audit->log('catalogue.promotion.ended', $promotion, reason: $reason, userId: $user->id);

            return $promotion;
        });
    }

    /**
     * Approved promotions that run at this branch at this moment (for the sale being priced).
     *
     * @return list<array<string, mixed>>
     */
    public function runningAt(int $branchId, CarbonInterface $at): array
    {
        return array_values(array_filter($this->approvedOn($at), fn ($rule) => PromotionEngine::runsAt($rule, $at, $branchId)));
    }

    /**
     * Approved promotions whose dates include this day (the till checks weekday and time itself).
     *
     * @return list<array<string, mixed>>
     */
    public function approvedOn(CarbonInterface $day, ?int $branchId = null): array
    {
        return Promotion::query()->with('targets')->where('status', Promotion::ACTIVE)
            ->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->orderBy('id')->get()
            ->map(fn (Promotion $p) => $p->rule())
            ->filter(fn ($rule) => $branchId === null || empty($rule['branchIds']) || in_array($branchId, $rule['branchIds'], true))
            ->values()->all();
    }

    private function review(Promotion $promotion, User $approver, string $status, ?string $note, string $action): Promotion
    {
        return DB::transaction(function () use ($promotion, $approver, $status, $note, $action) {
            $promotion->forceFill(['status' => $status, 'reviewed_by' => $approver->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->audit->log($action, $promotion, reason: $note, userId: $promotion->requested_by, approverId: $approver->id);

            return $promotion;
        });
    }

    private function assertReviewable(Promotion $promotion, User $approver): void
    {
        if (! $approver->can(Permissions::PROMOTIONS_APPROVE)) {
            throw new AuthorizationException;
        }
        if ($promotion->status !== Promotion::PENDING) {
            throw ValidationException::withMessages(['promotion' => 'This promotion has already been reviewed.']);
        }
        if ($promotion->requested_by === $approver->id) {
            throw new AuthorizationException('Someone else must approve a promotion you set up.');
        }
    }
}
