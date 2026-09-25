<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockTransfer;

/** @mixin StockTransfer */
class TransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'from' => ['branchCode' => $this->fromBranch->code, 'branchName' => $this->fromBranch->name, 'location' => InventoryResources::location($this->fromLocation)],
            'to' => ['branchCode' => $this->toBranch->code, 'branchName' => $this->toBranch->name, 'location' => InventoryResources::location($this->toLocation)],
            'note' => $this->note,
            'requestedBy' => InventoryResources::user($this->requester),
            'approvedBy' => InventoryResources::user($this->approver),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'dispatchedBy' => InventoryResources::user($this->dispatcher),
            'dispatchedAt' => $this->dispatched_at?->toIso8601String(),
            'receivedBy' => InventoryResources::user($this->receiver),
            'receivedAt' => $this->received_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'lines' => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'variant' => InventoryResources::variant($line->variant),
                'quantityRequested' => $line->quantity_requested,
                'quantityDispatched' => $line->quantity_dispatched,
                'quantityReceived' => $line->quantity_received,
            ])->values(),
        ];
    }
}
