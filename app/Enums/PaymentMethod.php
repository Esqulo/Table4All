<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case PIX = 'pix';
    case CARD = 'card';
    case COUPON = 'coupon';
    /** Paid by the customer in the app (Stripe). Only recorded by the app, never by staff. */
    case ONLINE = 'online';

    /**
     * Methods staff can register by hand.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::CASH, self::PIX, self::CARD, self::COUPON];
    }
}
