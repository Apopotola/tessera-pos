<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Enums\CountStatus;
use Modules\Inventory\Models\StockCount;

/**
 * Blind count: expected quantity and variance are withheld while counting.
 *
 * @mixin StockCount
 */
class CountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $blind = $this->status === CountStatus::Counting;
        $showCost = $request->user()?->can(Permissions::REPORTS_PROFIT_VIEW) ?? false;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'branchId' => $this->branch_id,
            'location' => InventoryResources::location($this->location),
            'status' => $this->status->value,
            'note' => $this->note,
            'createdBy' => InventoryResources::user($this->creator),
            'submittedBy' => InventoryResources::user($this->submitter),
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'reviewedBy' => InventoryResources::user($this->reviewer),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewNote' => $this->review_note,
            'createdAt' => $this->created_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->sortBy(fn ($l) => $l->variant->display_name)->map(fn ($line) => [
                'id' => $line->id,
                'variant' => InventoryResources::variant($line->variant),
                'countedQuantity' => $line->counted_quantity,
                'expectedQuantity' => $blind ? null : $line->expected_quantity,
                'variance' => $blind ? null : $line->variance,
                'varianceValueCents' => ! $blind && $showCost && $line->variance !== null ? $line->variance * (int) $line->unit_cost_cents : null,
            ])->values()),
            'lineCount' => $this->lines_count ?? $this->lines->count(),
        ];
    }
}
