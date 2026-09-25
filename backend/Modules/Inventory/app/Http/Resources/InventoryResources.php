<?php

namespace Modules\Inventory\Http\Resources;

use Modules\Auth\Models\User;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Organisation\Models\Location;

/** Small shared shapes used by the inventory resources. */
final class InventoryResources
{
    /** @return array{id: int, name: string}|null */
    public static function user(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    /** @return array{id: int, displayName: string, sku: string} */
    public static function variant(ProductVariant $variant): array
    {
        return ['id' => $variant->id, 'displayName' => $variant->display_name, 'sku' => $variant->sku];
    }

    /** @return array{id: int, branchId: int, name: string, type: string} */
    public static function location(Location $location): array
    {
        return ['id' => $location->id, 'branchId' => $location->branch_id, 'name' => $location->name, 'type' => $location->type->value];
    }
}
