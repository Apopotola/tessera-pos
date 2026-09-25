<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\AdjustmentType;

/**
 * Validation rules for inventory endpoints. Permissions, branch access and
 * maker–checker are enforced in the services (InventoryGuard), which every
 * entry point — API or future till/offline sync — must go through.
 */
final class InventoryRequests
{
    /** @return array<string, mixed> */
    public static function lines(bool $withCost = false): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variantId' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ] + ($withCost ? ['lines.*.unitCostCents' => ['nullable', 'integer', 'min:1', 'max:100000000']] : []);
    }

    /** @return array<string, mixed> */
    public static function adjustment(): array
    {
        return [
            'locationId' => ['required', 'integer', Rule::exists('locations', 'id')],
            'type' => ['required', Rule::enum(AdjustmentType::class)],
            'stage' => ['nullable', Rule::in(['receiving', 'storage', 'shelf', 'sale', 'transit'])],
            'reason' => ['required', 'string', 'max:500'],
            ...self::lines(withCost: true),
        ];
    }

    /** @return array<string, mixed> */
    public static function transfer(): array
    {
        return [
            'fromLocationId' => ['required', 'integer', Rule::exists('locations', 'id')],
            'toLocationId' => ['required', 'integer', 'different:fromLocationId', Rule::exists('locations', 'id')],
            'note' => ['nullable', 'string', 'max:500'],
            ...self::lines(),
        ];
    }

    /** lineId → quantity for dispatch/receive. @return array<string, mixed> */
    public static function lineQuantities(): array
    {
        return [
            'quantities' => ['sometimes', 'array'],
            'quantities.*' => ['integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    public static function count(): array
    {
        return [
            'locationId' => ['required', 'integer', Rule::exists('locations', 'id')],
            'note' => ['nullable', 'string', 'max:500'],
            'variantIds' => ['sometimes', 'array', 'max:2000'],
            'variantIds.*' => ['integer', Rule::exists('product_variants', 'id')],
        ];
    }

    /** @return array<string, mixed> */
    public static function countLines(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variantId' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'lines.*.countedQuantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function review(bool $noteRequired): array
    {
        return ['note' => [$noteRequired ? 'required' : 'nullable', 'string', 'max:500']];
    }
}
