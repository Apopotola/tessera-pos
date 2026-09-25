<?php

namespace Modules\Catalogue\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalogue\Models\VariantPrice;

/** @mixin VariantPrice */
class PriceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variantId' => $this->variant_id,
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'displayName' => $this->variant->display_name,
                'sku' => $this->variant->sku,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ] : null),
            'tier' => $this->tier->value,
            'priceCents' => $this->price_cents,
            'minPriceCents' => $this->min_price_cents,
            'effectiveFrom' => $this->effective_from->toIso8601String(),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'requestedBy' => $this->whenLoaded('requester', fn () => ['id' => $this->requester->id, 'name' => $this->requester->name]),
            'reviewedBy' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewNote' => $this->review_note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
