<?php

namespace Modules\Inventory\Enums;

/** requested → approved → in_transit → received; requested/approved may be cancelled. */
enum TransferStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
