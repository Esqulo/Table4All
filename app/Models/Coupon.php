<?php

namespace App\Models;

use App\Enums\CouponDiscountType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A discount code. user_id is the restaurant that offers it (and pays for the
 * discount); null means a platform coupon, valid at every restaurant and paid
 * by the platform. Codes are stored upper-case and matched that way.
 * customer_id makes it personal (loyalty rewards, or a coupon the owner made for
 * one customer): only that customer can use it.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $customer_id
 * @property string $code
 * @property CouponDiscountType $discount_type
 * @property float $discount_value
 * @property float|null $max_discount
 * @property Carbon|null $expires_at
 * @property int|null $max_uses
 * @property bool $multiple_per_table
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Coupon extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'customer_id',
        'code',
        'discount_type',
        'discount_value',
        'max_discount',
        'expires_at',
        'max_uses',
        'multiple_per_table',
    ];

    protected function casts(): array
    {
        return [
            'discount_type' => CouponDiscountType::class,
            'discount_value' => 'float',
            'max_discount' => 'float',
            'expires_at' => 'datetime',
            'max_uses' => 'integer',
            'multiple_per_table' => 'boolean',
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    /**
     * The coupon a customer typed, as seen at $restaurantId: the restaurant's own
     * code first, then a platform coupon with that code.
     */
    public static function findForRestaurant(string $code, int $restaurantId): ?self
    {
        return self::where('code', self::normalizeCode($code))
            ->where(fn (Builder $q) => $q->where('user_id', $restaurantId)->orWhereNull('user_id'))
            ->orderByRaw('user_id IS NULL')
            ->first();
    }

    /** Personal coupons (loyalty rewards) work only for their customer. */
    public function usableBy(int $userId): bool
    {
        return $this->customer_id === null || $this->customer_id === $userId;
    }

    public function isPlatform(): bool
    {
        return $this->user_id === null;
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt($at ?? now());
    }

    /**
     * Discount in cents on a payment of $amountCents, before the minimum-charge cap.
     */
    public function discountFor(int $amountCents): int
    {
        $discount = $this->discount_type === CouponDiscountType::PERCENT
            ? (int) round($amountCents * $this->discount_value / 100)
            : (int) round($this->discount_value * 100);

        if ($this->max_discount !== null) {
            $discount = min($discount, (int) round($this->max_discount * 100));
        }

        return max(0, min($discount, $amountCents));
    }

    /**
     * The only customer who can use it, for personal coupons.
     *
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Coupons the owner hands out by hand, leaving out loyalty rewards (the
     * program issues those to the customer on its own).
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotLoyaltyReward(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->from('loyalty_rewards')->whereColumn('loyalty_rewards.coupon_id', 'coupons.id'));
    }

    public function isLoyaltyReward(): bool
    {
        return DB::table('loyalty_rewards')->where('coupon_id', $this->id)->exists();
    }

    /** @return HasMany<OnlinePayment, $this> */
    public function onlinePayments(): HasMany
    {
        return $this->hasMany(OnlinePayment::class);
    }
}
