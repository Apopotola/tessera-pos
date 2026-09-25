<?php

namespace Modules\Inventory\Enums;

/** counting (blind) → submitted (expected frozen, variance visible) → approved (variance posted) | rejected. */
enum CountStatus: string
{
    case Counting = 'counting';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
