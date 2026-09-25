<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Http\Requests\Rules\CatalogueRules;
use Modules\Catalogue\Models\Pack;
use Modules\Catalogue\Models\ProductVariant;

/** POST /variants/{variant}/packs (create) or PUT /packs/{pack} (update). */
class PackRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::CATALOGUE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pack = $this->route('pack');
        $pack = $pack instanceof Pack ? $pack : null;
        /** @var ProductVariant $variant */
        $variant = $pack?->variant ?? $this->route('variant');

        $rules = [
            'name' => ['required', 'string', 'max:40'],
            'units' => ['required', 'integer', 'min:2', 'max:1000', Rule::unique('packs', 'units')->where('variant_id', $variant->id)->ignore($pack?->id)],
        ];

        if ($pack) {
            return $rules + ['isActive' => ['sometimes', 'boolean']];
        }

        return $rules + ['barcode' => ['nullable', ...CatalogueRules::barcode()]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['units.unique' => 'This variant already has a pack of that many units.'];
    }
}
