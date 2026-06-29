<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RestaurantTable extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'restaurant_tables';

    protected $fillable = [
        'user_id',
        'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TablePayment::class, 'restaurant_table_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'restaurant_table_product', 'restaurant_table_id', 'product_id')
            ->withPivot('quantity');
    }
}
