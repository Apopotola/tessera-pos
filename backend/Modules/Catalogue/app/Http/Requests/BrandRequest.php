<?php

namespace Modules\Catalogue\Http\Requests;

use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Http\Requests\Rules\CatalogueRules;
use Modules\Catalogue\Models\Brand;

/** Create (POST) or update (PUT /brands/{brand}) a brand. */
class BrandRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::CATALOGUE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $brand = $this->route('brand');
        $ignoreId = $brand instanceof Brand ? $brand->id : null;

        return [
            'name' => ['required', 'string', 'max:120', CatalogueRules::uniqueLower('brands', 'name', $ignoreId)],
            'country' => ['nullable', 'string', 'max:60'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
