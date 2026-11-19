<?php

namespace App\Models;

use App\Enums\OnlinePaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A payment a customer started in the app: a Stripe Checkout on the
 * restaurant's own account. Money is in cents:
 * - amount: what comes off the table's bill;
 * - discount: the coupon's part of it; charge = amount - discount is what Stripe collects;
 * - fee: the platform's share by tier; application_fee: what Stripe withholds for the
 *   platform (the fee minus a platform coupon's discount, never below zero);
 * - fee_refund: part of the platform's commission given back to a sponsored restaurant
 *   to cover Stripe's fee (never more than the commission withheld);
 * - owed: a platform coupon's discount beyond the commission, which the platform pays
 *   outside Stripe (Stripe Brazil doesn't allow free transfers) until marked paid;
 * - credited: what came off the bill once confirmed; refunded: the part of the charge
 *   sent back because the bill had been paid meanwhile (by a waiter, another customer).
 *   When that happens, discount and fee are recomputed on the credited amount.
 *
 * @property int $id
 * @property int $restaurant_table_id
 * @property int|null $user_id
 * @property int $restaurant_id
 * @property string $stripe_account_id
 * @property OnlinePaymentStatus $status
 * @property string|null $request_key
 * @property string $stripe_key
 * @property int $amount_cents
 * @property int $discount_cents
 * @property int $charge_cents
 * @property int|null $coupon_id
 * @property bool $coupon_by_platform
 * @property bool $coupon_multiple_per_table
 * @property string $fee_tier
 * @property int $fee_rate_bps
 * @property int $fee_cents
 * @property int $application_fee_cents
 * @property bool $platform_pays_stripe_fees
 * @property string|null $checkout_session_id
 * @property string|null $checkout_url
 * @property string|null $payment_intent_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $paid_at
 * @property int|null $credited_cents
 * @property int $refunded_cents
 * @property string|null $refund_id
 * @property Carbon|null $refunded_at
 * @property int|null $stripe_fee_cents
 * @property int|null $fee_refund_cents
 * @property string|null $fee_refund_id
 * @property int $owed_cents
 * @property Carbon|null $owed_paid_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class OnlinePayment extends Model
{
    protected $fillable = [
        'restaurant_table_id',
        'user_id',
        'restaurant_id',
        'stripe_account_id',
        'status',
        'request_key',
        'amount_cents',
        'discount_cents',
        'charge_cents',
        'coupon_id',
        'coupon_by_platform',
        'coupon_multiple_per_table',
        'fee_tier',
        'fee_rate_bps',
        'fee_cents',
        'application_fee_cents',
        'platform_pays_stripe_fees',
        'checkout_session_id',
        'checkout_url',
        'payment_intent_id',
        'expires_at',
        'paid_at',
    ];

    protected static function booted(): void
    {
        self::creating(function (OnlinePayment $payment): void {
            $payment->stripe_key ??= (string) Str::uuid();
        });
    }

    /** An idempotency key for one kind of Stripe request about this payment. */
    public function stripeKey(string $purpose): string
    {
        return $purpose.'-'.$this->stripe_key;
    }

    protected function casts(): array
    {
        return [
            'status' => OnlinePaymentStatus::class,
            'amount_cents' => 'integer',
            'discount_cents' => 'integer',
            'charge_cents' => 'integer',
            'coupon_by_platform' => 'boolean',
            'coupon_multiple_per_table' => 'boolean',
            'fee_rate_bps' => 'integer',
            'fee_cents' => 'integer',
            'application_fee_cents' => 'integer',
            'platform_pays_stripe_fees' => 'boolean',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'credited_cents' => 'integer',
            'refunded_cents' => 'integer',
            'refunded_at' => 'datetime',
            'stripe_fee_cents' => 'integer',
            'fee_refund_cents' => 'integer',
            'owed_cents' => 'integer',
            'owed_paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RestaurantTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    /** @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * A platform coupon's discount beyond the platform's commission: the part the
     * checkout couldn't net against the commission, owed to the restaurant.
     */
    public function couponShortfallCents(): int
    {
        return $this->coupon_by_platform ? max(0, $this->discount_cents - $this->fee_cents) : 0;
    }
}
