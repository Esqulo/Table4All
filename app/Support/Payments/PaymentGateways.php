<?php

namespace App\Support\Payments;

use App\Enums\PaymentProvider;
use Illuminate\Contracts\Container\Container;

/**
 * The gateway for each provider, resolved from the container when first needed
 * (so tests can swap either one for a fake).
 */
final class PaymentGateways
{
    public function __construct(private readonly Container $container) {}

    public function for(PaymentProvider $provider): CheckoutGateway
    {
        return match ($provider) {
            PaymentProvider::STRIPE => $this->container->make(PaymentGateway::class),
            PaymentProvider::MERCADO_PAGO => $this->container->make(MercadoPagoGateway::class),
        };
    }
}
