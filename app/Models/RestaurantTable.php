<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class RestaurantTable extends Model
{
    use SoftDeletes;

    protected $table = 'restaurant_tables';

    protected $fillable = [
        'user_id',
        'title',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
