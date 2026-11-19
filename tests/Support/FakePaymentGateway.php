<?php

namespace Tests\Support;

use App\Support\Payments\CheckoutRequest;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\PaymentGatewayException;

/**
 * Stripe stand-in for the tests: records what the app asked for and answers
 * with whatever state the test set (a checkout's status, the processing fee).
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, CheckoutRequest> checkouts created, by session id */
    public array $checkouts = [];

    /** @var array<string, string> account id of each checkout */
    public array $checkoutAccounts = [];

    /** @var array<string, array{status: string, payment_status: string, payment_intent: string|null, payment_intent_status: string|null}> */
    public array $sessions = [];

    /** @var list<string> */
    public array $expired = [];

    /** @var list<array{account: string, payment_intent: string, cents: int, key: string}> */
    public array $commissionRefunds = [];

    /** @var array<string, array{charges_enabled: bool, details_submitted: bool}> */
    public array $accounts = [];

    public ?int $processingFee = 100;

    public bool $failCheckout = false;

    /** @var array{type: string, account: string|null, object: array<string, mixed>}|null */
    public ?array $nextWebhookEvent = null;

    private int $sequence = 0;

    public function createAccount(string $email, string $businessName): string
    {
        $id = 'acct_test_'.(++$this->sequence);
        $this->accounts[$id] = ['charges_enabled' => false, 'details_submitted' => false];

        return $id;
    }

    public function onboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return 'https://connect.stripe.test/setup/'.$accountId;
    }

    public function accountStatus(string $accountId): array
    {
        return $this->accounts[$accountId] ?? ['charges_enabled' => false, 'details_submitted' => false];
    }

    public function createCheckout(string $accountId, CheckoutRequest $request): array
    {
        if ($this->failCheckout) {
            throw new PaymentGatewayException('Stripe is down');
        }

        $id = 'cs_test_'.(++$this->sequence);
        $this->checkouts[$id] = $request;
        $this->checkoutAccounts[$id] = $accountId;
        $this->sessions[$id] = ['status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null, 'payment_intent_status' => null];

        return ['id' => $id, 'url' => 'https://checkout.stripe.test/'.$id, 'expires_at' => $request->expiresAt];
    }

    public function checkoutStatus(string $accountId, string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? throw new PaymentGatewayException('No such checkout');
    }

    public function expireCheckout(string $accountId, string $sessionId): void
    {
        if (($this->sessions[$sessionId]['status'] ?? null) !== 'open') {
            throw new PaymentGatewayException('Checkout is not open');
        }

        $this->expired[] = $sessionId;
        $this->sessions[$sessionId]['status'] = 'expired';
    }

    public function processingFee(string $accountId, string $paymentIntentId): ?int
    {
        return $this->processingFee;
    }

    public bool $failRefund = false;

    /** @var list<array{account: string, payment_intent: string, cents: int, key: string}> */
    public array $refunds = [];

    public function refund(string $accountId, string $paymentIntentId, int $cents, string $idempotencyKey): string
    {
        if ($this->failRefund) {
            throw new PaymentGatewayException('Refund failed');
        }

        foreach ($this->refunds as $i => $refund) {
            if ($refund['key'] === $idempotencyKey) {
                return 're_test_'.$i;
            }
        }

        $this->refunds[] = ['account' => $accountId, 'payment_intent' => $paymentIntentId, 'cents' => $cents, 'key' => $idempotencyKey];

        return 're_test_'.(count($this->refunds) - 1);
    }

    public function refundCommission(string $accountId, string $paymentIntentId, int $cents, string $idempotencyKey): ?array
    {
        // Stripe answers a repeated idempotency key with the same refund.
        foreach ($this->commissionRefunds as $i => $refund) {
            if ($refund['key'] === $idempotencyKey) {
                return ['id' => 'fr_test_'.$i, 'cents' => $refund['cents']];
            }
        }

        // What is left of the commission withheld on this payment's checkout.
        $sessionId = substr($paymentIntentId, 3);
        $withheld = isset($this->checkouts[$sessionId]) ? $this->checkouts[$sessionId]->applicationFeeCents : 0;
        $alreadyGiven = array_sum(array_column(
            array_filter($this->commissionRefunds, fn ($r) => $r['payment_intent'] === $paymentIntentId),
            'cents',
        ));
        $amount = min($cents, $withheld - $alreadyGiven);

        if ($amount <= 0) {
            return null;
        }

        $this->commissionRefunds[] = ['account' => $accountId, 'payment_intent' => $paymentIntentId, 'cents' => $amount, 'key' => $idempotencyKey];

        return ['id' => 'fr_test_'.(count($this->commissionRefunds) - 1), 'cents' => $amount];
    }

    public function webhookEvent(string $payload, string $signature): ?array
    {
        return $signature === 'valid' ? $this->nextWebhookEvent : null;
    }

    /** The customer pays the checkout on Stripe. */
    public function pay(string $sessionId): void
    {
        $this->sessions[$sessionId] = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_'.$sessionId, 'payment_intent_status' => 'succeeded'];
    }

    /** The customer chose Pix: the checkout is done, the money not yet. */
    public function awaitPix(string $sessionId): void
    {
        $this->sessions[$sessionId] = ['status' => 'complete', 'payment_status' => 'unpaid', 'payment_intent' => 'pi_'.$sessionId, 'payment_intent_status' => 'requires_action'];
    }

    public function lastSessionId(): string
    {
        return (string) array_key_last($this->checkouts);
    }
}
