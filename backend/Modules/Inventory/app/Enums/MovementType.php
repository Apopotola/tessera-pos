<?php

namespace Modules\Inventory\Enums;

/** Why a stock movement happened. Sales, returns and goods receipts are added by their modules. */
enum MovementType: string
{
    case Opening = 'opening';
    case Found = 'found';
    case Breakage = 'breakage';
    case Expired = 'expired';
    case Damaged = 'damaged';
    case Missing = 'missing';
    case TransferDispatch = 'transfer_dispatch';
    case TransferReceive = 'transfer_receive';
    case CountVariance = 'count_variance';
    case GoodsReceived = 'goods_received';
    case SupplierReturn = 'supplier_return';
    case Sale = 'sale';
    case CustomerReturn = 'customer_return';
    case BottleOpened = 'bottle_opened'; // bottle moved from shelf stock to the bar to sell by the tot
}
