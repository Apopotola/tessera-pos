<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Http\Requests\Rules\CatalogueRules;

class StoreProductRequest extends PermissionRequest
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
            'variants' => ['required', 'array', 'min:1', 'max:20'],
            ...CatalogueRules::variantFields(prefix: 'variants.*.'),
            'variants.*.sku' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', 'distinct:ignore_case', Rule::unique('product_variants', 'sku')],
            'variants.*.barcodes' => ['sometimes', 'array', 'max:10'],
            'variants.*.barcodes.*' => ['distinct', ...CatalogueRules::barcode()],
            'variants.*.retailPriceCents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $seen = [];
            foreach ((array) $this->input('variants', []) as $i => $variant) {
                $key = ($variant['volumeMl'] ?? '').'|'.($variant['container'] ?? '');
                if (isset($seen[$key])) {
                    $validator->errors()->add("variants.{$i}.volumeMl", 'This size and container is already listed.');
                }
                $seen[$key] = true;
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'variants.*.volumeMl' => 'volume',
            'variants.*.sku' => 'SKU',
            'variants.*.taxRateId' => 'tax rate',
            'variants.*.barcodes.*' => 'barcode',
            'variants.*.retailPriceCents' => 'retail price',
        ];
    }
}
