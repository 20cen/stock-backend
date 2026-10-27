<?php

namespace App\Enums;

enum LocationType: string
{
    case Warehouse = 'warehouse';
    case Shop = 'shop';
    case Defective = 'defective';
}
