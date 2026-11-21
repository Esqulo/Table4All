<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation to become a platform admin. The token only exists in the e-mail; the
 * table keeps its hash.
 *
 * @property int $id
 * @property string $email
 * @property string $token_hash
 * @property int|null $invited_by_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdminInvitation extends Model
{
    public const VALID_HOURS = 72;

    protected $guarded = ['id'];

    /**
     * Creates the invitation and returns it with the plain token (to put in the link).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $email, User $inviter): array
    {
        $token = Str::random(64);

        $invitation = self::create([
            'email' => Str::lower($email),
            'token_hash' => hash('sha256', $token),
            'invited_by_id' => $inviter->id,
            'expires_at' => now()->addHours(self::VALID_HOURS),
        ]);

        return [$invitation, $token];
    }

    /** The pending invitation a link's token belongs to, if any. */
    public static function findPending(string $token): ?self
    {
        return self::pending()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
