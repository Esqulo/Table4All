<?php

namespace App\Support\Payments;

/**
 * What TableCheckout needs from a payment provider, whichever it is (Stripe,
 * Mercado Pago). The restaurant has its own account with the provider
 * ($accountId); customers pay on the provider's hosted checkout, charged on that
 * account, and the provider withholds the platform's fee from it.
 *
 * Every method throws PaymentGatewayException when the provider fails or
 * refuses, so callers never depend on an SDK's or HTTP client's exceptions.
 */
interface CheckoutGateway
{
    /**
     * @return array{id: string, url: string, expires_at: int}
     */
    public function createCheckout(string $accountId, CheckoutRequest $request): array;

    /**
     * The checkout as the provider sees it now, in Stripe's terms: status open,
     * complete or expired; payment_status paid or unpaid; the payment's id (a
     * PaymentIntent on Stripe) and its status ("canceled" or
     * "requires_payment_method" when an asynchronous payment failed).
     *
     * $reference is the CheckoutRequest's reference, which some providers search by.
     *
     * @return array{status: string, payment_status: string, payment_intent: string|null, payment_intent_status: string|null}
     */
    public function checkoutStatus(string $accountId, string $sessionId, string $reference): array;

    /**
     * Closes an open checkout so it can no longer be paid. Throws when it can't be
     * closed because it was paid (or is being paid) meanwhile.
     */
    public function expireCheckout(string $accountId, string $sessionId, string $reference): void;

    /** The provider's processing fee on a paid payment, in cents (null when not settled yet). */
    public function processingFee(string $accountId, string $paymentId): ?int;

    /**
     * Gives $cents of a paid payment back to the customer (with the matching share
     * of the platform's fee); returns the refund id.
     */
    public function refund(string $accountId, string $paymentId, int $cents, string $idempotencyKey): string;

    /**
     * Whether the platform can give part of its commission on a payment back to the
     * account through the provider (refundCommission). When it can't, what it owes
     * the restaurant is paid outside the provider (admin payouts).
     */
    public function refundsCommission(): bool;

    /**
     * Gives back up to $cents of the platform's commission on a payment to the account
     * (what is left of it after refunds). Returns the refund id and the amount given back,
     * or null when there is no commission left to give back.
     *
     * @return array{id: string, cents: int}|null
     */
    public function refundCommission(string $accountId, string $paymentId, int $cents, string $idempotencyKey): ?array;
}
