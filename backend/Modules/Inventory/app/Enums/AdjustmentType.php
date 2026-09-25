<?php

namespace Modules\Inventory\Enums;

use Modules\Authorization\Support\Permissions;

enum AdjustmentType: string
{
    case Opening = 'opening';   // go-live stock with cost
    case Found = 'found';       // stock turned up
    case Breakage = 'breakage'; // broken glass
    case Expired = 'expired';
    case Damaged = 'damaged';   // unsellable, e.g. damaged on arrival
    case Missing = 'missing';   // unexplained loss

    /** +1 adds stock, −1 removes it. */
    public function direction(): int
    {
        return in_array($this, [self::Opening, self::Found], true) ? 1 : -1;
    }

    /** Losses cashiers and storekeepers may report; everything else needs inventory.adjust. */
    public function isLossReport(): bool
    {
        return in_array($this, [self::Breakage, self::Expired, self::Damaged], true);
    }

    /** @return list<string> permissions that may create this type */
    public function creatorPermissions(): array
    {
        return $this->isLossReport()
            ? [Permissions::INVENTORY_BREAKAGE_REPORT, Permissions::INVENTORY_ADJUST]
            : [Permissions::INVENTORY_ADJUST];
    }

    public function movementType(): MovementType
    {
        return MovementType::from($this->value);
    }
}
