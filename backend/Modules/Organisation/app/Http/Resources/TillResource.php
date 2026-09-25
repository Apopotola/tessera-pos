<?php

namespace Modules\Organisation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Organisation\Models\Till;

/** @mixin Till */
class TillResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branchId' => $this->branch_id,
            'name' => $this->name,
            'description' => $this->description,
            'defaultFloatCents' => $this->default_float_cents,
            'isPaired' => $this->paired_at !== null,
            'pairedAt' => $this->paired_at?->toIso8601String(),
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'isActive' => $this->is_active,
        ];
    }
}
