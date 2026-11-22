<?php

namespace App\Support\Payments;

/**
 * Stripe Connect: the checkout operations every provider has, plus Stripe's
 * own account onboarding and webhook. Restaurants have their own connected
 * account; customers pay on a hosted checkout charged directly on that account,
 * with the platform's application fee.
 */
interface PaymentGateway extends CheckoutGateway
{
    /** Creates the restaurant's connected account and returns its id. */
    public function createAccount(string $email, string $businessName): string;

    /** A one-time link to the provider's onboarding for the account. */
    public function onboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string;

    /** @return array{charges_enabled: bool, details_submitted: bool} */
    public function accountStatus(string $accountId): array;

    /**
     * A verified webhook event, or null when the signature doesn't match.
     *
     * @return array{type: string, account: string|null, object: array<string, mixed>}|null
     */
    public function webhookEvent(string $payload, string $signature): ?array;
}
