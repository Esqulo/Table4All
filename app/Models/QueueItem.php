<?php

namespace App\Models;

use App\Enums\QueueItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property QueueItemStatus $status
 */
final class QueueItem extends Model
{
    protected $fillable = [
        'restaurant_table_id',
        'product_id',
        'queue_id',
        'quantity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => QueueItemStatus::class,
        ];
    }

    /** @return BelongsTo<RestaurantTable, $this> */
    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<RestaurantQueue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(RestaurantQueue::class, 'queue_id');
    }
}
