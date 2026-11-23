<?php

namespace App\Models;

use App\Support\ServiceFee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A table of a restaurant (user_id is the owner). closed_at has no cast: it is
 * read back as the database string and set with now() when the table closes.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $responsible_user_id
 * @property int|null $waiter_id the team member in charge (App\Support\TableAssignment)
 * @property string|null $service_fee_type the service fee terms copied from the restaurant (App\Support\ServiceFee)
 * @property string|null $service_fee_value
 * @property string|null $service_fee_cap
 * @property bool $service_fee_waived
 * @property string $title
 * @property string|null $access_code
 * @property Carbon|string|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class RestaurantTable extends Model
{
    use SoftDeletes;

    protected $table = 'restaurant_tables';

    protected $fillable = [
        'user_id',
        'title',
        'access_code',
    ];

    protected function casts(): array
    {
        return [
            'service_fee_waived' => 'boolean',
        ];
    }

    /**
     * A table opens under the restaurant's current service fee: later changes to the
     * setting don't touch a bill already running (ServiceFee).
     */
    protected static function booted(): void
    {
        self::creating(function (RestaurantTable $table): void {
            if (! array_key_exists('service_fee_type', $table->getAttributes())) {
                $table->forceFill(ServiceFee::snapshot($table->user_id));
            }
        });
    }

    /**
     * Customers join by code alone (no restaurant in the URL), so a code must be
     * unique across every restaurant's open tables, not just the caller's.
     *
     * @param  (callable(): int)|null  $random  number source (tests force collisions with it)
     */
    public static function generateUniqueCode(?callable $random = null): string
    {
        $random ??= fn (): int => random_int(0, 999999);

        do {
            $code = str_pad((string) $random(), 6, '0', STR_PAD_LEFT);
            $exists = self::withTrashed()
                ->whereNull('closed_at')
                ->where('access_code', $code)
                ->exists();
        } while ($exists);

        return $code;
    }

    /**
     * Tables of restaurants that are switched on: a switched-off restaurant's codes
     * lead nowhere for customers (a primary-key lookup per table).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfActiveRestaurant(Builder $query): Builder
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('users')
            ->whereColumn('users.id', 'restaurant_tables.user_id')
            ->whereNull('users.deactivated_at'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    /** @return HasMany<TablePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(TablePayment::class, 'restaurant_table_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'restaurant_table_product', 'restaurant_table_id', 'product_id')
            ->withPivot('quantity');
    }
}
