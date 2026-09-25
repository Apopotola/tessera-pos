<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;

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
}
