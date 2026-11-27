<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Models\CashCheck;

/**
 * A cash check: shown only after the count, so expected and variance are always included.
 *
 * @mixin CashCheck
 */
class CashCheckResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shiftId' => $this->shift_id,
            'tillId' => $this->till_id,
            'branchId' => $this->branch_id,
            'tillName' => $this->whenLoaded('till', fn () => $this->till->name),
            'countedBy' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'periodStartedAt' => $this->period_started_at->toIso8601String(),
            'countedAt' => $this->created_at->toIso8601String(),
            'expectedCashCents' => $this->expected_cash_cents,
            'countedCashCents' => $this->counted_cash_cents,
            'varianceCents' => $this->variance_cents,
            // List of {denominationCents, count}, like a shift's count.
            'countBreakdown' => $this->count_breakdown
                ? collect($this->count_breakdown)->map(fn ($count, $cents) => ['denominationCents' => (int) $cents, 'count' => (int) $count])->sortByDesc('denominationCents')->values()->all()
                : null,
            'note' => $this->note,
            'reviewedBy' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewNote' => $this->review_note,
        ];
    }
}
