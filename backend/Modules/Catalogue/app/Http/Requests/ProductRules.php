<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Catalogue\Models\Product;

final class ProductRules
{
    /** @return array<string, mixed> */
    public static function product(): array
    {
        return [
            'brandId' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'categoryId' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:150'],
            'abv' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * One product per name within a brand (products_brand_name_lower_unique). A second size or
     * container of the same drink, such as the can of a bottled beer, is a size of that product.
     */
    public static function uniqueName(Validator $validator, Request $request, ?int $ignoreProductId = null): void
    {
        $name = trim((string) $request->input('name'));
        if ($name === '' || $validator->errors()->hasAny(['name', 'brandId'])) {
            return;
        }

        $brandId = $request->input('brandId');
        $taken = Product::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($brandId, fn ($q) => $q->where('brand_id', (int) $brandId), fn ($q) => $q->whereNull('brand_id'))
            ->when($ignoreProductId, fn ($q) => $q->whereKeyNot($ignoreProductId))
            ->value('name');

        if ($taken !== null) {
            $validator->errors()->add('name', "{$taken} already exists. To sell it in another size or container (for example a can), open {$taken} in Products and use Add size.");
        }
    }
}
