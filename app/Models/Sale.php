<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Sale extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'sale_price',
        'starts_at',
        'ends_at',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'sale_price' => 'float',
            'starts_at'  => 'datetime',
            'ends_at'    => 'datetime',
        ];
    }
}
