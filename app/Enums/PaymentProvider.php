<?php

namespace App\Enums;

/**
 * Where a restaurant receives the payments customers make in the app. The
 * restaurant links its own account with one (or both) and picks which one
 * new checkouts go to; each payment remembers the provider it was made on.
 */
enum PaymentProvider: string
{
    case STRIPE = 'stripe';
    case MERCADO_PAGO = 'mercadopago';

    /** The provider's name as customers and restaurants know it (a brand: not translated). */
    public function label(): string
    {
        return match ($this) {
            self::STRIPE => 'Stripe',
            self::MERCADO_PAGO => 'Mercado Pago',
        };
    }
}
