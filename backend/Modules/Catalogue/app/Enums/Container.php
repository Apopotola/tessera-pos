<?php

namespace Modules\Catalogue\Enums;

enum Container: string
{
    case Bottle = 'bottle';
    case Can = 'can';
    case Keg = 'keg';
    case Box = 'box';
    case Pouch = 'pouch';
    case Other = 'other';
}
