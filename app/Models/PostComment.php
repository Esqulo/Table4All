<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment on a restaurant post.
 *
 * @property int $id
 * @property int $post_id
 * @property int $user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PostComment extends Model
{
    public const BODY_MAX = 500;

    protected $fillable = ['post_id', 'user_id', 'body'];

    /** @return BelongsTo<RestaurantPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(RestaurantPost::class, 'post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
