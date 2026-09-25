<?php

namespace Modules\Purchasing\Enums;

/** draft → approved → sent → partially_received → received; draft/approved/sent may be cancelled. */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function canReceive(): bool
    {
        return in_array($this, [self::Approved, self::Sent, self::PartiallyReceived], true);
    }
}
