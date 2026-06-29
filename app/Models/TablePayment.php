<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TablePayment extends Model
{
    protected $fillable = [
        'restaurant_table_id',
        'method',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'float',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }
}
