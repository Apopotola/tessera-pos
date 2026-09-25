<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Http\Requests\Rules\CatalogueRules;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariant;

/**
 * POST /products/{product}/variants (create) or PUT /variants/{variant} (update).
 */
class VariantRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::CATALOGUE_MANAGE;
    }

    private function variant(): ?ProductVariant
    {
        $variant = $this->route('variant');

        return $variant instanceof ProductVariant ? $variant : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $variant = $this->variant();
        $rules = CatalogueRules::variantFields($variant?->id);

        if ($variant) {
            return $rules + ['isActive' => ['sometimes', 'boolean']];
        }

        return $rules + [
            'barcodes' => ['sometimes', 'array', 'max:10'],
            'barcodes.*' => ['distinct', ...CatalogueRules::barcode()],
            'retailPriceCents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $variant = $this->variant();
            $product = $variant?->product ?? $this->route('product');
            if (! $product instanceof Product) {
                return;
            }

            $duplicate = $product->variants()
                ->where('volume_ml', $this->integer('volumeMl'))
                ->where('container', $this->input('container'))
                ->when($variant, fn ($q) => $q->whereKeyNot($variant->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('volumeMl', 'This product already has this size and container.');
            }
        }];
    }
}
