<?php

namespace Modules\Catalogue\Enums;

enum PriceTier: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Tot = 'tot'; // price per tot for variants sold by the tot
}
