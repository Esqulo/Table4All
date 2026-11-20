<?php

namespace App\Models;

use App\Enums\QueueItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One ordered product waiting in a prep queue or on the delivery board.
 * The price is snapshotted when the order is placed.
 *
 * @property int $id
 * @property int $restaurant_table_id
 * @property int $product_id
 * @property int|null $queue_id
 * @property int|null $ordered_by_user_id
 * @property int $quantity
 * @property string|null $notes for the kitchen or the waiter ("sem cebola", "ao ponto")
 * @property QueueItemStatus $status
 * @property float $price
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class QueueItem extends Model
{
    protected $fillable = [
        'restaurant_table_id',
        'product_id',
        'queue_id',
        'ordered_by_user_id',
        'quantity',
        'notes',
        'price',
        'status',
    ];

    /** Longest note on an item: a short instruction, not a message. */
    public const NOTES_MAX = 140;

    /** A note as stored: one line, trimmed, null when empty. */
    public static function cleanNotes(mixed $notes): ?string
    {
        if (! is_string($notes)) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', $notes));

        return $clean === '' ? null : mb_substr($clean, 0, self::NOTES_MAX);
    }

    protected function casts(): array
    {
        return [
            'status' => QueueItemStatus::class,
            'price' => 'float',
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
        // A product deleted while an order for it is still in progress must keep
        // showing on the kitchen and delivery boards instead of breaking them.
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<RestaurantQueue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(RestaurantQueue::class, 'queue_id');
    }

    /** @return BelongsTo<User, $this> */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by_user_id');
    }
}
