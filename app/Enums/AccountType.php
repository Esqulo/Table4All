<?php

namespace App\Enums;

enum AccountType: string
{
    case RESTAURANT = 'restaurant';
    case CUSTOMER = 'customer';
    case WAITER = 'waiter';
}
