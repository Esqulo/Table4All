<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A commercial plan: the platform's share of a restaurant's online payments. The admin
 * creates and edits them; a restaurant points at one by key (users.fee_tier), and may
 * have its own percentage on top (users.fee_percent_override). Changing a percentage only
 * affects payments started afterwards: each payment stores the rate it was charged with.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $fee_percent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'name', 'fee_percent'])]
class FeeTier extends Model
{
    /** Highest commission an admin can set, on a tier or for one restaurant. */
    public const MAX_PERCENT = 30;

    /** Key of the tier restaurants get when they have none (or theirs was removed). */
    public static function defaultKey(): string
    {
        return (string) config('payments.default_tier');
    }

    /** A new, unused key from the tier's name: "Parceiro Sul" → "parceiro_sul". */
    public static function keyFor(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 28, '') ?: 'tier';
        $key = $base;

        for ($i = 2; self::where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }

    public function isDefault(): bool
    {
        return $this->key === self::defaultKey();
    }

    protected function casts(): array
    {
        return [
            'fee_percent' => 'decimal:2',
        ];
    }
}
