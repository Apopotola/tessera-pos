<?php

namespace Modules\Organisation\Enums;

enum LocationType: string
{
    case ShopFloor = 'shop_floor';
    case Store = 'store';
    case Warehouse = 'warehouse';
    case Quarantine = 'quarantine';
    /** Stock dispatched to this branch but not yet received. Created on demand; excluded from on-hand and cost. */
    case Transit = 'transit';

    /** Stock in these locations can be sold at the till. */
    public function isSellable(): bool
    {
        return $this === self::ShopFloor;
    }
}
