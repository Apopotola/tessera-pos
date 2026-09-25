<?php

namespace Modules\Catalogue\Enums;

enum PriceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
