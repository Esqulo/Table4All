<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone's emoji reaction to a post (one per person per post).
 *
 * @property int $id
 * @property int $post_id
 * @property int $user_id
 * @property string $emoji
 */
final class PostReaction extends Model
{
    protected $fillable = ['post_id', 'user_id', 'emoji'];
}
