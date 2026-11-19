<?php

namespace App\Support\Payments;

/** What a customer's checkout charges, and where the customer goes afterwards. */
final readonly class CheckoutRequest
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public int $chargeCents,
        public int $applicationFeeCents,
        public string $description,
        public string $successUrl,
        public string $cancelUrl,
        public int $expiresAt,
        public ?string $customerEmail,
        public string $locale,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {}
}
