<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A picture of the restaurant's venue.
 *
 * @property int $id
 * @property int $user_id
 * @property string $path
 * @property int $position
 */
final class RestaurantPhoto extends Model
{
    /** Keeps the gallery light to load on mobile data. */
    public const MAX_PER_RESTAURANT = 12;

    protected $fillable = ['user_id', 'path', 'position'];

    protected $appends = ['url'];

    protected $hidden = ['path'];

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return Attribute<string, never> */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk('public')->url($this->path));
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
