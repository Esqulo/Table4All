<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A social post of a restaurant (promotion, event, news), shown on its public profile.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $body
 * @property string|null $image
 * @property string|null $image_aspect
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RestaurantPost extends Model
{
    public const BODY_MAX = 2000;

    /** Crop formats offered when posting, as CSS aspect ratios. */
    public const ASPECTS = ['square' => 1, 'portrait' => 4 / 5, 'landscape' => 16 / 9];

    protected $fillable = ['user_id', 'body', 'image', 'image_aspect'];

    protected $appends = ['image_url'];

    protected $hidden = ['image'];

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<PostReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(PostReaction::class, 'post_id');
    }

    /** @return HasMany<PostComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class, 'post_id');
    }

    /** @return Attribute<string|null, never> */
    protected function imageUrl(): Attribute
    {
        /** @phpstan-ignore return.type */
        return Attribute::get(fn (): ?string => $this->image ? Storage::disk('public')->url($this->image) : null);
    }
}
