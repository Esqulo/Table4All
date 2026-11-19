<?php

namespace App\Support\Payments;

use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe Connect with Standard accounts and direct charges: the checkout is
 * created on the restaurant's account, so the payment (and Stripe's fee) is
 * theirs, and Stripe withholds the platform's application fee from it.
 */
final class StripePaymentGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    /**
     * @param  list<string>  $webhookSecrets
     */
    public function __construct(
        private readonly ?string $secret,
        private readonly array $webhookSecrets,
        private readonly string $currency,
    ) {}

    /**
     * Accounts v2 (Stripe refuses v1 accounts for new Connect platforms): a merchant
     * with its own full Stripe dashboard, which pays Stripe's fees and whose losses
     * Stripe covers, the equivalent of a v1 "standard" account.
     */
    public function createAccount(string $email, string $businessName): string
    {
        return $this->call(fn (StripeClient $stripe) => $stripe->v2->core->accounts->create([
            'contact_email' => $email,
            'display_name' => $businessName,
            'identity' => ['country' => 'br'],
            'dashboard' => 'full',
            'defaults' => [
                'currency' => $this->currency,
                'locales' => ['pt-BR'],
                'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'],
            ],
            'configuration' => [
                'merchant' => ['capabilities' => [
                    'card_payments' => ['requested' => true],
                    'pix_payments' => ['requested' => true],
                ]],
            ],
        ])->id);
    }

    public function onboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->call(fn (StripeClient $stripe) => $stripe->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    'refresh_url' => $refreshUrl,
                    'return_url' => $returnUrl,
                ],
            ],
        ])->url);
    }

    public function accountStatus(string $accountId): array
    {
        $account = $this->call(fn (StripeClient $stripe) => $stripe->v2->core->accounts->retrieve(
            $accountId,
            ['include' => ['configuration.merchant', 'requirements']],
        ))->toArray();

        $cardPayments = $account['configuration']['merchant']['capabilities']['card_payments']['status'] ?? null;
        $waitingOnOwner = array_filter(
            $account['requirements']['entries'] ?? [],
            fn (array $entry) => ($entry['awaiting_action_from'] ?? null) === 'user',
        );

        return [
            'charges_enabled' => $cardPayments === 'active',
            'details_submitted' => $waitingOnOwner === [],
        ];
    }

    public function createCheckout(string $accountId, CheckoutRequest $request): array
    {
        $paymentIntent = [
            'description' => $request->description,
            'metadata' => $request->metadata,
        ];

        if ($request->applicationFeeCents > 0) {
            $paymentIntent['application_fee_amount'] = $request->applicationFeeCents;
        }

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $this->currency,
                    'unit_amount' => $request->chargeCents,
                    'product_data' => ['name' => $request->description],
                ],
            ]],
            'payment_intent_data' => $paymentIntent,
            'metadata' => $request->metadata,
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'expires_at' => $request->expiresAt,
            'locale' => $request->locale,
        ];

        if ($request->customerEmail !== null) {
            $params['customer_email'] = $request->customerEmail;
        }

        $session = $this->call(fn (StripeClient $stripe) => $stripe->checkout->sessions->create($params, [
            'stripe_account' => $accountId,
            'idempotency_key' => $request->idempotencyKey,
        ]));

        return [
            'id' => $session->id,
            'url' => (string) $session->url,
            'expires_at' => (int) $session->expires_at,
        ];
    }

    public function checkoutStatus(string $accountId, string $sessionId): array
    {
        $session = $this->call(fn (StripeClient $stripe) => $stripe->checkout->sessions->retrieve(
            $sessionId,
            ['expand' => ['payment_intent']],
            ['stripe_account' => $accountId],
        ));

        $intent = $session->payment_intent;

        return [
            'status' => (string) $session->status,
            'payment_status' => (string) $session->payment_status,
            'payment_intent' => is_string($intent) ? $intent : $intent?->id,
            'payment_intent_status' => is_string($intent) ? null : $intent?->status,
        ];
    }

    public function expireCheckout(string $accountId, string $sessionId): void
    {
        $this->call(fn (StripeClient $stripe) => $stripe->checkout->sessions->expire(
            $sessionId,
            [],
            ['stripe_account' => $accountId],
        ));
    }

    public function processingFee(string $accountId, string $paymentIntentId): ?int
    {
        $intent = $this->call(fn (StripeClient $stripe) => $stripe->paymentIntents->retrieve(
            $paymentIntentId,
            ['expand' => ['latest_charge.balance_transaction']],
            ['stripe_account' => $accountId],
        ));

        $charge = $intent->latest_charge;
        $balance = $charge instanceof Charge ? $charge->balance_transaction : null;

        if (! $balance instanceof BalanceTransaction) {
            return null;
        }

        // Stripe's own fee (and taxes on it); the platform's application fee is listed too.
        $fee = 0;
        foreach ($balance->fee_details as $detail) {
            if ($detail->type !== 'application_fee') {
                $fee += (int) $detail->amount;
            }
        }

        return $fee;
    }

    public function refund(string $accountId, string $paymentIntentId, int $cents, string $idempotencyKey): string
    {
        return $this->call(fn (StripeClient $stripe) => $stripe->refunds->create([
            'payment_intent' => $paymentIntentId,
            'amount' => $cents,
            // The platform gives back its share of what is refunded.
            'refund_application_fee' => true,
        ], [
            'stripe_account' => $accountId,
            'idempotency_key' => $idempotencyKey,
        ])->id);
    }

    /**
     * Stripe Brazil refuses platform-to-account transfers without a platform charge to
     * draw from (source_transaction); refunding part of the application fee is how
     * the platform can give money back on a direct charge.
     */
    public function refundCommission(string $accountId, string $paymentIntentId, int $cents, string $idempotencyKey): ?array
    {
        $intent = $this->call(fn (StripeClient $stripe) => $stripe->paymentIntents->retrieve(
            $paymentIntentId,
            ['expand' => ['latest_charge']],
            ['stripe_account' => $accountId],
        ));

        $charge = $intent->latest_charge;
        $feeId = $charge instanceof Charge ? $charge->application_fee : null;
        $feeId = is_object($feeId) ? $feeId->id : $feeId;

        if (! is_string($feeId) || $feeId === '') {
            return null;
        }

        $fee = $this->call(fn (StripeClient $stripe) => $stripe->applicationFees->retrieve($feeId));
        $amount = min($cents, (int) $fee->amount - (int) $fee->amount_refunded);

        if ($amount <= 0) {
            return null;
        }

        $refund = $this->call(fn (StripeClient $stripe) => $stripe->applicationFees->createRefund(
            $feeId,
            ['amount' => $amount],
            ['idempotency_key' => $idempotencyKey],
        ));

        return ['id' => (string) $refund->id, 'cents' => $amount];
    }

    public function webhookEvent(string $payload, string $signature): ?array
    {
        foreach ($this->webhookSecrets as $secret) {
            try {
                $event = Webhook::constructEvent($payload, $signature, $secret);
            } catch (SignatureVerificationException|UnexpectedValueException) {
                continue;
            }

            $data = $event->toArray();
            $object = $data['data']['object'] ?? [];

            // Accounts v2 send "thin" events: no snapshot, just the id of what changed.
            if ($object === [] && isset($data['related_object']['id'])) {
                $object = ['id' => (string) $data['related_object']['id']];
            }

            return [
                'type' => (string) ($data['type'] ?? ''),
                'account' => isset($data['account']) ? (string) $data['account'] : null,
                'object' => is_array($object) ? $object : [],
            ];
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  callable(StripeClient): T  $request
     * @return T
     */
    private function call(callable $request): mixed
    {
        if ($this->secret === null || $this->secret === '') {
            throw new PaymentGatewayException('Stripe is not configured (STRIPE_SECRET).');
        }

        $this->client ??= new StripeClient(['api_key' => $this->secret, 'max_network_retries' => 2]);

        try {
            return $request($this->client);
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
