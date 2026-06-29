<?php

namespace App\Enums;

enum PriceType: string
{
    case UNIT = 'unit';
    case KG = 'kg';
    case GRAMS_100 = '100g';
    case LITER = 'liter';
    case PORTION = 'portion';
}
