<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A promotion: a weekly window (periodic: days + start/end time) or a dated
 * range (scheduled: starts_at/ends_at). user_id is the restaurant owner.
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property string $type
 * @property float $sale_price
 * @property array<int, int|string>|null $days
 * @property string|null $start_time
 * @property string|null $end_time
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Sale extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'type',
        'sale_price',
        'days',
        'start_time',
        'end_time',
        'starts_at',
        'ends_at',
    ];

    /**
     * Sales of a restaurant that are active at the given moment: either a weekly
     * window (periodic) or a dated range (scheduled).
     *
     * @param  Builder<Sale>  $query
     * @return Builder<Sale>
     */
    public function scopeActiveFor(Builder $query, int $restaurantId, ?Carbon $at = null): Builder
    {
        // Promotion windows are entered in local wall-clock time, not UTC.
        $at = ($at ?? now())->copy()->setTimezone((string) config('app.business_timezone'));
        $currentDay = (int) $at->dayOfWeek;
        $currentTime = $at->format('H:i:s');
        $nowDatetime = $at->format('Y-m-d H:i:s');

        return $query
            ->where('user_id', $restaurantId)
            ->where(function (Builder $q) use ($currentDay, $currentTime, $nowDatetime) {
                $q->where(function (Builder $q) use ($currentDay, $currentTime) {
                    $q->where('type', 'periodic')
                        ->whereJsonContains('days', [$currentDay])
                        ->where('start_time', '<=', $currentTime)
                        ->where('end_time', '>=', $currentTime);
                })->orWhere(function (Builder $q) use ($nowDatetime) {
                    $q->where('type', 'scheduled')
                        ->where('starts_at', '<=', $nowDatetime)
                        ->where('ends_at', '>=', $nowDatetime);
                });
            });
    }

    /**
     * Map of product_id => sale_price for the restaurant's currently active sales.
     *
     * @return Collection<int, float>
     */
    public static function activePriceMap(int $restaurantId): Collection
    {
        /** @var Collection<int, float> */
        return self::query()->activeFor($restaurantId)->pluck('sale_price', 'product_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'sale_price' => 'float',
            'days' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
