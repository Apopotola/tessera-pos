<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Models\StockAdjustment;

/** @mixin StockAdjustment */
class AdjustmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $showCost = $request->user()?->can(Permissions::REPORTS_PROFIT_VIEW) ?? false;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'branchId' => $this->branch_id,
            'location' => InventoryResources::location($this->location),
            'type' => $this->type->value,
            'direction' => $this->type->direction(),
            'stage' => $this->stage,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'requestedBy' => InventoryResources::user($this->requester),
            'reviewedBy' => InventoryResources::user($this->reviewer),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewNote' => $this->review_note,
            'source' => $this->source_type ? ['type' => $this->source_type, 'id' => $this->source_id] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'lines' => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'variant' => InventoryResources::variant($line->variant),
                'quantity' => $line->quantity,
                'unitCostCents' => $showCost ? $line->unit_cost_cents : null,
            ])->values(),
        ];
    }
}
