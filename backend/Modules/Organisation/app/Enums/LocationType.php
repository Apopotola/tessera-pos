<?php

namespace Modules\Organisation\Enums;

enum LocationType: string
{
    case ShopFloor = 'shop_floor';
    case Store = 'store';
    case Warehouse = 'warehouse';
    case Quarantine = 'quarantine';

    /** Stock in these locations can be sold at the till. */
    public function isSellable(): bool
    {
        return $this === self::ShopFloor;
    }
}
