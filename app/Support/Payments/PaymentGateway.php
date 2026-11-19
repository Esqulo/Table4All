<?php

namespace App\Support\Payments;

/**
 * What the app needs from the payment provider (Stripe Connect). Restaurants
 * have their own connected account; customers pay on a hosted checkout
 * charged directly on that account, with the platform's application fee.
 *
 * Every method throws PaymentGatewayException when the provider fails or
 * refuses, so callers never depend on the SDK's exceptions.
 */
interface PaymentGateway
{
    /** Creates the restaurant's connected account and returns its id. */
    public function createAccount(string $email, string $businessName): string;

    /** A one-time link to the provider's onboarding for the account. */
    public function onboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string;

    /** @return array{charges_enabled: bool, details_submitted: bool} */
    public function accountStatus(string $accountId): array;

    /**
     * @return array{id: string, url: string, expires_at: int}
     */
    public function createCheckout(string $accountId, CheckoutRequest $request): array;

    /**
     * The checkout as the provider sees it now.
     *
     * @return array{status: string, payment_status: string, payment_intent: string|null, payment_intent_status: string|null}
     */
    public function checkoutStatus(string $accountId, string $sessionId): array;

    /** Closes an open checkout so it can no longer be paid. */
    public function expireCheckout(string $accountId, string $sessionId): void;

    /** The provider's processing fee on a paid payment, in cents (null when not settled yet). */
    public function processingFee(string $accountId, string $paymentIntentId): ?int;

    /**
     * Gives $cents of a paid payment back to the customer (with the matching share
     * of the platform's fee); returns the refund id.
     */
    public function refund(string $accountId, string $paymentIntentId, int $cents, string $idempotencyKey): string;

    /**
     * Gives back up to $cents of the platform's commission on a payment to the account
     * (what is left of it after refunds). Returns the refund id and the amount given back,
     * or null when there is no commission left to give back.
     *
     * @return array{id: string, cents: int}|null
     */
    public function refundCommission(string $accountId, string $paymentIntentId, int $cents, string $idempotencyKey): ?array;

    /**
     * A verified webhook event, or null when the signature doesn't match.
     *
     * @return array{type: string, account: string|null, object: array<string, mixed>}|null
     */
    public function webhookEvent(string $payload, string $signature): ?array;
}
