<?php

namespace Modules\Catalogue\Http\Requests\Rules;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Catalogue\Enums\Container;

/** Shared validation rules for catalogue requests. */
final class CatalogueRules
{
    /** Case-insensitive uniqueness on a text column, optionally ignoring one row. */
    public static function uniqueLower(string $table, string $column, ?int $ignoreId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table, $column, $ignoreId) {
            $exists = DB::table($table)
                ->whereRaw("lower({$column}) = ?", [mb_strtolower(trim((string) $value))])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                $fail('This :attribute already exists.');
            }
        };
    }

    /** @return array<string, mixed> */
    public static function variantFields(?int $ignoreVariantId = null, string $prefix = ''): array
    {
        return [
            "{$prefix}volumeMl" => ['required', 'integer', 'min:1', 'max:100000'],
            "{$prefix}container" => ['required', Rule::enum(Container::class)],
            "{$prefix}sku" => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('product_variants', 'sku')->ignore($ignoreVariantId)],
            "{$prefix}taxRateId" => ['required', 'integer', Rule::exists('tax_rates', 'id')->where('is_active', true)],
            "{$prefix}etimsItemClassCode" => ['nullable', 'string', 'max:20'],
            "{$prefix}trackBatches" => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<mixed> */
    public static function barcode(): array
    {
        return ['string', 'min:4', 'max:64', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('barcodes', 'code')];
    }
}
