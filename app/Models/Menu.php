<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
