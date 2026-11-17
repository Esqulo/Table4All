<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A public menu of a restaurant (user_id is the owner).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed>|null $layout
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Menu extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'name', 'layout'];

    /** The print layout is only needed by the print editor. */
    protected $hidden = ['layout'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'layout' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
