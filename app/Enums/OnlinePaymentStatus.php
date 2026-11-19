<?php

namespace App\Enums;

enum OnlinePaymentStatus: string
{
    /** Checkout open on Stripe: the customer is still paying. */
    case PENDING = 'pending';
    /** Checkout finished with a method that confirms later (Pix, boleto). */
    case PROCESSING = 'processing';
    case PAID = 'paid';
    /**
     * Charged, but the bill was already settled by the time it was confirmed: the
     * whole charge went back to the customer and nothing came off the bill.
     */
    case REFUNDED = 'refunded';
    /** Abandoned, expired or failed: nothing was charged. */
    case CANCELED = 'canceled';

    /**
     * Statuses whose amount (and coupon) is held while the payment may still succeed.
     *
     * @return list<string>
     */
    public static function holding(): array
    {
        return [self::PENDING->value, self::PROCESSING->value, self::PAID->value];
    }

    /** @return list<string> */
    public static function inFlight(): array
    {
        return [self::PENDING->value, self::PROCESSING->value];
    }
}
