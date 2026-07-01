<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property string $email
 * @property string $token
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WaiterInvitation extends Model
{
    protected $fillable = ['restaurant_id', 'email', 'token', 'accepted_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restaurant_id');
    }

    /** @param Builder<WaiterInvitation> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')
              ->where('created_at', '>=', now()->subDays(7));
    }
}
