<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Enums\MovementType;
use Modules\Organisation\Models\Location;

/** One line to post to the ledger. Quantity is signed; unitCostCents is only used for stock coming in. */
final readonly class StockEntry
{
    public function __construct(
        public Location $location,
        public int $variantId,
        public int $quantity,
        public MovementType $type,
        public ?int $unitCostCents = null,
        public ?string $reason = null,
    ) {}
}
