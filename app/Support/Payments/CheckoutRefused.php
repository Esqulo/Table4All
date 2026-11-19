<?php

namespace App\Support\Payments;

use RuntimeException;

/**
 * A checkout the customer can't start as asked (amount over the balance, a
 * coupon that doesn't apply, ...). Carries a translation key for the field.
 */
final class CheckoutRefused extends RuntimeException
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(
        public readonly string $field,
        public readonly string $key,
        public readonly array $params = [],
    ) {
        parent::__construct($key);
    }

    public function translated(): string
    {
        return __($this->key, $this->params);
    }
}
