<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Models\Shift;

/**
 * Expected cash and variance are only exposed once the shift is closed (blind count).
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $closed = ! $this->isOpen();

        return [
            'id' => $this->id,
            'tillId' => $this->till_id,
            'branchId' => $this->branch_id,
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'openingFloatCents' => $this->opening_float_cents,
            'openedAt' => $this->opened_at->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),
            'isOpen' => ! $closed,
            'expectedCashCents' => $closed ? $this->expected_cash_cents : null,
            'countedCashCents' => $closed ? $this->counted_cash_cents : null,
            'varianceCents' => $closed ? $this->variance_cents : null,
            // Drops are known to the cashier (they handed the cash over); the count only after closing.
            'dropsCents' => (int) $this->drops_cents,
            // List of {denominationCents, count}: numeric-keyed maps get renumbered by API resources.
            'countBreakdown' => $closed && $this->count_breakdown
                ? collect($this->count_breakdown)->map(fn ($count, $cents) => ['denominationCents' => (int) $cents, 'count' => (int) $count])->sortByDesc('denominationCents')->values()->all()
                : null,
            'varianceReason' => $this->variance_reason,
            'reviewedBy' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewNote' => $this->review_note,
        ];
    }
}
