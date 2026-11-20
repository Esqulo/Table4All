<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A table calling the waiter or asking for the bill (user_id is the restaurant owner).
 * Open until resolved: answered by staff, cancelled by the table, or the table closed.
 *
 * @property int $id
 * @property int $user_id
 * @property int $restaurant_table_id
 * @property string $type
 * @property int|null $requested_by_user_id
 * @property Carbon|null $resolved_at
 * @property string|null $resolution
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TableCall extends Model
{
    public const WAITER = 'waiter';

    public const BILL = 'bill';

    public const TYPES = [self::WAITER, self::BILL];

    public const ANSWERED = 'answered';

    public const CANCELLED = 'cancelled';

    public const CLOSED = 'closed';

    protected $fillable = ['user_id', 'restaurant_table_id', 'type', 'requested_by_user_id'];

    /**
     * Opens the call unless the table already has one of this type open
     * (the partial unique index makes a repeat a no-op, races included).
     */
    public static function open(RestaurantTable $table, string $type, int $requestedBy): void
    {
        $now = now();

        DB::table('table_calls')->insertOrIgnore([
            'user_id' => $table->user_id,
            'restaurant_table_id' => $table->id,
            'type' => $type,
            'requested_by_user_id' => $requestedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Resolves the table's open calls (of one type, or all). Conditional, so a call
     * answered at the same moment by someone else is resolved once.
     *
     * @return int how many were resolved
     */
    public static function resolve(int $tableId, string $resolution, ?int $by = null, ?string $type = null): int
    {
        return self::where('restaurant_table_id', $tableId)
            ->whereNull('resolved_at')
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->update([
                'resolved_at' => now(),
                'resolution' => $resolution,
                'resolved_by_user_id' => $by,
                'updated_at' => now(),
            ]);
    }

    /** @return BelongsTo<RestaurantTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
