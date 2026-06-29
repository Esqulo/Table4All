<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH   = 'cash';
    case PIX    = 'pix';
    case CARD   = 'card';
    case COUPON = 'coupon';
}
