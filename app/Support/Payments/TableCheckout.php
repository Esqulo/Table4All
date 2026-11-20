<?php

namespace App\Support\Payments;

use App\Enums\AccountType;
use App\Enums\OnlinePaymentStatus;
use App\Enums\PaymentMethod;
use App\Models\Coupon;
use App\Models\OnlinePayment;
use App\Models\RestaurantTable;
use App\Models\TablePayment;
use App\Models\User;
use App\Support\ServiceFee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customers paying their table in the app.
 *
 * A payment starts as a checkout on the restaurant's Stripe account. While it is
 * open, its amount (and coupon) is held, so two customers can't pay the same
 * balance or stack coupons that don't combine. Once Stripe confirms it (return
 * from the checkout, the webhook, or the reconcile command, whichever comes
 * first), it is recorded on the table as an "online" payment plus a "coupon"
 * payment for the discount, and the bill drops by the whole amount.
 *
 * Money is in integer cents throughout.
 */
final class TableCheckout
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Opens a checkout for $amountCents of the table's bill and returns it (with
     * the URL to send the customer to). A retried request (same request key)
     * returns the checkout it already opened.
     *
     * @throws CheckoutRefused
     */
    public function start(
        User $payer,
        RestaurantTable $table,
        int $amountCents,
        ?string $couponCode,
        ?string $requestKey,
        string $locale,
    ): OnlinePayment {
        if ($requestKey !== null) {
            $previous = OnlinePayment::where('user_id', $payer->id)->where('request_key', $requestKey)->first();

            if ($previous !== null) {
                return $this->resume($previous);
            }
        }

        $restaurant = User::whereKey($table->user_id)->firstOrFail();

        if (! $restaurant->acceptsOnlinePayments()) {
            throw new CheckoutRefused('amount', 'payments.unavailable');
        }

        // A customer has one checkout at a time per table: starting another closes
        // the one they left open (and frees what it held).
        OnlinePayment::where('restaurant_table_id', $table->id)
            ->where('user_id', $payer->id)
            ->where('status', OnlinePaymentStatus::PENDING->value)
            ->get()
            ->each(fn (OnlinePayment $open) => $this->cancel($open));

        try {
            $payment = DB::transaction(function () use ($payer, $table, $restaurant, $amountCents, $couponCode, $requestKey) {
                // Serializes with other checkouts, deliveries and closing on this table, so
                // the balance and the coupons in use can't change between check and hold.
                $locked = RestaurantTable::whereKey($table->id)->lockForUpdate()->firstOrFail();

                if ($locked->closed_at !== null) {
                    throw new CheckoutRefused('amount', 'payments.table_closed');
                }

                $quote = $this->quote($payer, $locked, $amountCents, $couponCode);

                return OnlinePayment::create([
                    'restaurant_table_id' => $locked->id,
                    'user_id' => $payer->id,
                    'restaurant_id' => $restaurant->id,
                    'stripe_account_id' => $restaurant->stripe_account_id,
                    'status' => OnlinePaymentStatus::PENDING,
                    'request_key' => $requestKey,
                    'amount_cents' => $amountCents,
                    'discount_cents' => $quote['discount_cents'],
                    'charge_cents' => $quote['charge_cents'],
                    'coupon_id' => $quote['coupon']?->id,
                    'coupon_by_platform' => $quote['coupon']?->isPlatform() ?? false,
                    'coupon_multiple_per_table' => $quote['coupon']->multiple_per_table ?? false,
                    'fee_tier' => $restaurant->feeTier(),
                    'fee_rate_bps' => $quote['fee_rate_bps'],
                    'fee_cents' => $quote['fee_cents'],
                    'application_fee_cents' => $quote['application_fee_cents'],
                    'platform_pays_stripe_fees' => $restaurant->platform_pays_stripe_fees,
                    'expires_at' => now()->addMinutes((int) config('payments.checkout_minutes')),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The same request arrived twice at once; the other one is opening the checkout.
            $previous = OnlinePayment::where('user_id', $payer->id)->where('request_key', $requestKey)->firstOrFail();

            return $this->resume($previous);
        }

        $restaurantName = (string) $restaurant->name;

        try {
            $session = $this->gateway->createCheckout($payment->stripe_account_id, new CheckoutRequest(
                chargeCents: $payment->charge_cents,
                applicationFeeCents: $payment->application_fee_cents,
                description: __('payments.checkout_description', ['table' => $table->title, 'restaurant' => $restaurantName]),
                successUrl: route('customer.payments.return', $payment).'?result=success',
                cancelUrl: route('customer.payments.return', $payment).'?result=cancel',
                expiresAt: $payment->expires_at?->getTimestamp() ?? now()->addMinutes(30)->getTimestamp(),
                customerEmail: $payer->email,
                locale: $locale === 'pt_BR' ? 'pt-BR' : 'en',
                idempotencyKey: $payment->stripeKey('checkout'),
                metadata: [
                    'online_payment_id' => (string) $payment->id,
                    'restaurant_table_id' => (string) $table->id,
                ],
            ));
        } catch (PaymentGatewayException $e) {
            Log::warning('Stripe checkout failed', ['online_payment_id' => $payment->id, 'error' => $e->getMessage()]);
            $this->markCanceled($payment);

            throw new CheckoutRefused('amount', 'payments.gateway_error');
        }

        $payment->forceFill([
            'checkout_session_id' => $session['id'],
            'checkout_url' => $session['url'],
        ])->save();

        return $payment;
    }

    /**
     * What a payment of $amountCents with $couponCode would cost the payer now,
     * or why it can't be made. Called under the table lock by start().
     *
     * @return array{coupon: Coupon|null, discount_cents: int, charge_cents: int, fee_rate_bps: int, fee_cents: int, application_fee_cents: int}
     *
     * @throws CheckoutRefused
     */
    public function quote(User $payer, RestaurantTable $table, int $amountCents, ?string $couponCode): array
    {
        $minCharge = (int) config('payments.min_charge_cents');

        if ($amountCents < $minCharge) {
            throw new CheckoutRefused('amount', 'payments.amount_too_low', ['min' => self::money($minCharge)]);
        }

        $payable = $this->payableCents($table, $payer->id);

        if ($payable <= 0) {
            throw new CheckoutRefused('amount', 'payments.nothing_to_pay');
        }

        if ($amountCents > $payable) {
            throw new CheckoutRefused('amount', 'payments.amount_too_high', ['max' => self::money($payable)]);
        }

        $coupon = null;
        $discount = 0;

        if ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = $this->applicableCoupon($payer, $table, $couponCode);
            // A coupon never takes the charge below Stripe's minimum.
            $discount = min($coupon->discountFor($amountCents), $amountCents - $minCharge);

            if ($discount <= 0) {
                throw new CheckoutRefused('coupon', 'payments.coupon_no_discount');
            }
        }

        $restaurant = User::whereKey($table->user_id)->firstOrFail();
        $rate = $restaurant->feeRateBps();
        $byPlatform = $coupon?->isPlatform() ?? false;

        // The platform's share is taken on what the restaurant gets for the bill: the
        // whole amount when the platform pays the discount, the amount minus the
        // discount when the restaurant does.
        $fee = (int) round(($byPlatform ? $amountCents : $amountCents - $discount) * $rate / 10000);
        // A platform coupon is netted against the fee; what's left is owed outside Stripe (admin payouts).
        $applicationFee = $byPlatform ? max(0, $fee - $discount) : $fee;

        return [
            'coupon' => $coupon,
            'discount_cents' => $discount,
            'charge_cents' => $amountCents - $discount,
            'fee_rate_bps' => $rate,
            'fee_cents' => $fee,
            'application_fee_cents' => min($applicationFee, $amountCents - $discount),
        ];
    }

    /**
     * What is left to pay on the table, minus what other checkouts hold.
     */
    public function payableCents(RestaurantTable $table, int $payerId): int
    {
        $total = $this->billCents($table->id);
        $paid = $this->paidCents($table->id);

        // The payer's own open checkout is closed when they start a new one, so it
        // doesn't count; their Pix waiting for confirmation does.
        $held = (int) OnlinePayment::where('restaurant_table_id', $table->id)
            ->whereIn('status', OnlinePaymentStatus::inFlight())
            ->where(fn ($q) => $q
                ->where('user_id', '!=', $payerId)
                ->orWhereNull('user_id')
                ->orWhere('status', OnlinePaymentStatus::PROCESSING->value))
            ->sum('amount_cents');

        return $total - $paid - $held;
    }

    /**
     * @throws CheckoutRefused
     */
    private function applicableCoupon(User $payer, RestaurantTable $table, string $code): Coupon
    {
        $coupon = Coupon::findForRestaurant($code, $table->user_id);

        // Someone else's personal coupon is as good as a code that doesn't exist.
        if ($coupon === null || ! $coupon->usableBy($payer->id)) {
            throw new CheckoutRefused('coupon', 'payments.coupon_invalid');
        }

        if ($coupon->isExpired()) {
            throw new CheckoutRefused('coupon', 'payments.coupon_expired');
        }

        if ($coupon->max_uses !== null) {
            // Locked so two checkouts (even at different tables) can't both take
            // the last use: the second waits here and then sees the first one's hold.
            Coupon::whereKey($coupon->id)->lockForUpdate()->value('id');

            // Paid uses plus checkouts still holding it, except this customer's own
            // open checkout at this table, which a new one replaces.
            $uses = OnlinePayment::where('coupon_id', $coupon->id)
                ->whereIn('status', OnlinePaymentStatus::holding())
                ->where(fn ($q) => $q
                    ->where('restaurant_table_id', '!=', $table->id)
                    ->orWhere('user_id', '!=', $payer->id)
                    ->orWhereNull('user_id')
                    ->orWhere('status', '!=', OnlinePaymentStatus::PENDING->value))
                ->count();

            if ($uses >= $coupon->max_uses) {
                throw new CheckoutRefused('coupon', 'payments.coupon_exhausted');
            }
        }

        // Coupons already used (or held by an open checkout) at this table.
        $used = OnlinePayment::where('restaurant_table_id', $table->id)
            ->whereNotNull('coupon_id')
            ->whereIn('status', OnlinePaymentStatus::holding())
            ->where(fn ($q) => $q
                ->where('user_id', '!=', $payer->id)
                ->orWhereNull('user_id')
                ->orWhere('status', '!=', OnlinePaymentStatus::PENDING->value))
            ->get(['user_id', 'coupon_multiple_per_table']);

        if ($used->contains(fn (OnlinePayment $p) => ! $p->coupon_multiple_per_table)) {
            throw new CheckoutRefused('coupon', 'payments.coupon_table_locked');
        }

        if (! $coupon->multiple_per_table && $used->isNotEmpty()) {
            throw new CheckoutRefused('coupon', 'payments.coupon_not_combinable');
        }

        if ($used->contains('user_id', $payer->id)) {
            throw new CheckoutRefused('coupon', 'payments.coupon_already_used');
        }

        return $coupon;
    }

    /**
     * Brings the payment up to date with Stripe. Safe to call any number of
     * times, from any path (return URL, webhook, reconcile).
     */
    public function sync(OnlinePayment $payment): OnlinePayment
    {
        if (in_array($payment->status, [OnlinePaymentStatus::PAID, OnlinePaymentStatus::REFUNDED], true)) {
            $this->settle($payment);

            return $payment;
        }

        if ($payment->status === OnlinePaymentStatus::CANCELED) {
            return $payment;
        }

        if ($payment->checkout_session_id === null) {
            // Never reached Stripe (the request died between hold and checkout).
            if ($payment->created_at !== null && $payment->created_at->lt(now()->subMinutes(5))) {
                $this->markCanceled($payment);
            }

            return $payment;
        }

        $checkout = $this->gateway->checkoutStatus($payment->stripe_account_id, $payment->checkout_session_id);

        if ($checkout['status'] === 'complete' && in_array($checkout['payment_status'], ['paid', 'no_payment_required'], true)) {
            $this->markPaid($payment, $checkout['payment_intent']);
        } elseif ($checkout['status'] === 'complete') {
            // An asynchronous method (Pix, boleto) the customer hasn't paid yet, or one that failed.
            if (in_array($checkout['payment_intent_status'], ['canceled', 'requires_payment_method'], true)) {
                $this->markCanceled($payment);
            } else {
                OnlinePayment::whereKey($payment->id)
                    ->where('status', OnlinePaymentStatus::PENDING->value)
                    ->update([
                        'status' => OnlinePaymentStatus::PROCESSING->value,
                        'payment_intent_id' => $checkout['payment_intent'],
                        'updated_at' => now(),
                    ]);
            }
        } elseif ($checkout['status'] === 'expired') {
            $this->markCanceled($payment);
        }

        return $payment->refresh();
    }

    /** The customer gives up on an open checkout: it is closed on Stripe and releases its hold. */
    public function cancel(OnlinePayment $payment): OnlinePayment
    {
        if ($payment->status !== OnlinePaymentStatus::PENDING) {
            return $payment;
        }

        if ($payment->checkout_session_id !== null) {
            try {
                $this->gateway->expireCheckout($payment->stripe_account_id, $payment->checkout_session_id);
            } catch (PaymentGatewayException) {
                // Already paid or already closed: find out which.
                return $this->sync($payment);
            }
        }

        $this->markCanceled($payment);

        return $payment->refresh();
    }

    /**
     * Records a confirmed payment on the table (once, whatever path confirms it first).
     *
     * Only what the bill still owes comes off it: if a waiter or another customer paid
     * meanwhile, the excess is refunded. The coupon covers the credited part first,
     * so the customer gets back real money; discount and fee are recomputed on what
     * was credited.
     */
    public function markPaid(OnlinePayment $payment, ?string $paymentIntentId): void
    {
        $recorded = DB::transaction(function () use ($payment, $paymentIntentId): bool {
            $locked = OnlinePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [OnlinePaymentStatus::PAID, OnlinePaymentStatus::REFUNDED], true)) {
                return false;
            }

            // Serializes with other confirmations and with closing on this table, so two
            // payments landing together can't both be credited against the same balance.
            RestaurantTable::whereKey($locked->restaurant_table_id)->lockForUpdate()->first(['id']);

            $owed = max(0, $this->billCents($locked->restaurant_table_id) - $this->paidCents($locked->restaurant_table_id));
            $credited = min($locked->amount_cents, $owed);
            $discount = min($locked->discount_cents, $credited);
            $charged = $credited - $discount;
            $refund = $locked->charge_cents - $charged;

            $attributes = [
                // A checkout canceled here can still be paid on Stripe if the customer had it
                // open; the money was taken, so it is recorded all the same.
                'status' => $credited > 0 ? OnlinePaymentStatus::PAID : OnlinePaymentStatus::REFUNDED,
                'paid_at' => now(),
                'payment_intent_id' => $paymentIntentId ?? $locked->payment_intent_id,
                'credited_cents' => $credited,
                'refunded_cents' => $refund,
            ];

            if ($refund > 0) {
                $base = $locked->coupon_by_platform ? $credited : $credited - $discount;
                $attributes['discount_cents'] = $discount;
                $attributes['fee_cents'] = (int) round($base * $locked->fee_rate_bps / 10000);
            }

            $locked->forceFill($attributes)->save();

            $now = now();
            $row = [
                'restaurant_table_id' => $locked->restaurant_table_id,
                'online_payment_id' => $locked->id,
                'registered_by_id' => $locked->user_id,
                'registered_by_type' => AccountType::CUSTOMER->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $rows = [];

            if ($charged > 0) {
                $rows[] = [...$row, 'method' => PaymentMethod::ONLINE->value, 'amount' => $charged / 100];
            }

            if ($discount > 0) {
                $rows[] = [...$row, 'method' => PaymentMethod::COUPON->value, 'amount' => $discount / 100];
            }

            if ($rows !== []) {
                TablePayment::insert($rows);
            }

            return true;
        });

        $payment->refresh();

        if ($recorded) {
            $this->settle($payment);
        }
    }

    /**
     * What follows a confirmed payment: refunding the customer what the bill no
     * longer owed, and sending the restaurant what the platform owes (a platform
     * coupon's discount, Stripe's fee when the platform pays it). Failures are
     * left for the reconcile command; idempotency keys make retries safe.
     */
    public function settle(OnlinePayment $payment): void
    {
        if (! in_array($payment->status, [OnlinePaymentStatus::PAID, OnlinePaymentStatus::REFUNDED], true)
            || $payment->settled_at !== null) {
            return;
        }

        try {
            if ($payment->refunded_cents > 0 && $payment->refund_id === null && $payment->payment_intent_id !== null) {
                $refundId = $this->gateway->refund(
                    $payment->stripe_account_id,
                    $payment->payment_intent_id,
                    $payment->refunded_cents,
                    $payment->stripeKey('refund'),
                );
                $payment->forceFill(['refund_id' => $refundId, 'refunded_at' => now()])->save();
            }

            // The customer's money first: not settled until the refund went through.
            if ($payment->refunded_cents > 0 && $payment->refund_id === null) {
                return;
            }

            if ($payment->platform_pays_stripe_fees && $payment->stripe_fee_cents === null && $payment->payment_intent_id !== null) {
                $fee = $this->gateway->processingFee($payment->stripe_account_id, $payment->payment_intent_id);

                if ($fee !== null) {
                    $payment->forceFill(['stripe_fee_cents' => $fee])->save();
                }
            }

            // Sponsored: Stripe's fee comes back out of the platform's commission, as far as it goes.
            if ($payment->platform_pays_stripe_fees && $payment->fee_refund_cents === null) {
                if ($payment->stripe_fee_cents === null) {
                    return;
                }

                $given = $payment->stripe_fee_cents > 0 && $payment->payment_intent_id !== null
                    ? $this->gateway->refundCommission(
                        $payment->stripe_account_id,
                        $payment->payment_intent_id,
                        $payment->stripe_fee_cents,
                        $payment->stripeKey('fee-refund'),
                    )
                    : null;

                $payment->forceFill([
                    'fee_refund_cents' => $given['cents'] ?? 0,
                    'fee_refund_id' => $given['id'] ?? null,
                ])->save();
            }

            $payment->forceFill([
                // A platform coupon beyond the commission: paid outside Stripe (admin payouts).
                'owed_cents' => $payment->couponShortfallCents(),
                'settled_at' => now(),
            ])->save();
        } catch (PaymentGatewayException $e) {
            Log::warning('Settling an online payment failed', ['online_payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }
    }

    /** The products plus the service fee. */
    private function billCents(int $tableId): int
    {
        return ServiceFee::bill($tableId)['total'];
    }

    private function paidCents(int $tableId): int
    {
        return (int) round(100 * (float) DB::table('table_payments')
            ->where('restaurant_table_id', $tableId)
            ->sum('amount'));
    }

    private function markCanceled(OnlinePayment $payment): void
    {
        OnlinePayment::whereKey($payment->id)
            ->whereIn('status', OnlinePaymentStatus::inFlight())
            ->update(['status' => OnlinePaymentStatus::CANCELED->value, 'updated_at' => now()]);
    }

    /**
     * A checkout found again by its request key: still open (or already paid), or refused.
     *
     * @throws CheckoutRefused
     */
    private function resume(OnlinePayment $payment): OnlinePayment
    {
        if (in_array($payment->status, [OnlinePaymentStatus::PAID, OnlinePaymentStatus::REFUNDED], true)) {
            return $payment;
        }

        if ($payment->status !== OnlinePaymentStatus::PENDING || $payment->checkout_url === null) {
            throw new CheckoutRefused('amount', 'payments.checkout_closed');
        }

        return $payment;
    }

    private static function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
