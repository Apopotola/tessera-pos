<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Models\Product;

class UpdateProductRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::CATALOGUE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ProductRules::product(),
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $product = $this->route('product');
            ProductRules::uniqueName($validator, $this, $product instanceof Product ? $product->id : null);
        }];
    }
}
