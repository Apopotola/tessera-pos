<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Models\StockMovement;

/** @mixin StockMovement */
class MovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $showCost = $request->user()?->can(Permissions::REPORTS_PROFIT_VIEW) ?? false;

        return [
            'id' => $this->id,
            'occurredAt' => $this->occurred_at->toIso8601String(),
            'branchCode' => $this->branch->code,
            'location' => $this->location->name,
            'variant' => InventoryResources::variant($this->variant),
            'quantity' => $this->quantity,
            'type' => $this->movement_type->value,
            'reference' => $this->reference,
            'documentType' => $this->document_type,
            'documentId' => $this->document_id,
            'reason' => $this->reason,
            'user' => InventoryResources::user($this->user),
            'approvedBy' => InventoryResources::user($this->approver),
            'unitCostCents' => $showCost ? $this->unit_cost_cents : null,
        ];
    }
}
