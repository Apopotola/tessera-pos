<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Http\Requests\Rules\CatalogueRules;
use Modules\Catalogue\Models\ProductVariant;

class BarcodeRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::CATALOGUE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ProductVariant $variant */
        $variant = $this->route('variant');

        return [
            'code' => ['required', ...CatalogueRules::barcode()],
            'packId' => ['nullable', 'integer', Rule::exists('packs', 'id')->where('variant_id', $variant->id)],
        ];
    }
}
