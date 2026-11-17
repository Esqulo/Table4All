<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A customer's review of a restaurant, 1 to 5 stars (one per customer).
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 */
final class RestaurantReview extends Model
{
    protected $fillable = ['restaurant_id', 'user_id', 'rating', 'comment'];

    /**
     * Average (0 when there are none) and count, aggregated in SQL.
     *
     * @return array{average: float, count: int}
     */
    public static function summaryFor(int $restaurantId): array
    {
        $row = self::where('restaurant_id', $restaurantId)
            ->select([DB::raw('COUNT(*) as total'), DB::raw('AVG(rating) as average')])
            ->toBase()
            ->first();

        $count = (int) ($row->total ?? 0);

        return [
            'average' => $count > 0 ? round((float) $row->average, 1) : 0.0,
            'count' => $count,
        ];
    }

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
