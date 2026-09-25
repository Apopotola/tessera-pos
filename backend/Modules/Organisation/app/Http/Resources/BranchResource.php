<?php

namespace Modules\Organisation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Organisation\Models\Branch;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'isWarehouse' => $this->is_warehouse,
            'isActive' => $this->is_active,
        ];
    }
}
