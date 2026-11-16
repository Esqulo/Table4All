<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opening interval of a restaurant on a weekday, in business-timezone
 * wall-clock time. `closes_at <= opens_at` means it closes after midnight.
 *
 * @property int $id
 * @property int $user_id
 * @property int $weekday
 * @property string $opens_at
 * @property string $closes_at
 */
final class RestaurantOpeningHour extends Model
{
    protected $fillable = ['user_id', 'weekday', 'opens_at', 'closes_at'];

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
