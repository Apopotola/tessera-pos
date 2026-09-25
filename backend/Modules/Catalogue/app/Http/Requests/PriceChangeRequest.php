<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Enums\PriceTier;

/** POST /variants/{variant}/prices — request (or, for approvers, apply) a new price. */
class PriceChangeRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::PRICES_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tier' => ['required', Rule::enum(PriceTier::class)],
            'branchId' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'priceCents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'minPriceCents' => ['nullable', 'integer', 'min:1', 'lte:priceCents'],
            'effectiveFrom' => ['nullable', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['priceCents' => 'price', 'minPriceCents' => 'minimum price', 'effectiveFrom' => 'effective date'];
    }
}
