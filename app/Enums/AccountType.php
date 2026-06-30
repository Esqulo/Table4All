<?php

namespace App\Enums;

enum AccountType: string
{
    case ADMIN = 'admin';
    case RESTAURANT = 'restaurant';
    case CUSTOMER = 'customer';
    case WAITER = 'waiter';
}
