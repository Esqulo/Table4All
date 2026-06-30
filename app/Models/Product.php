<?php

namespace App\Models;

use App\Enums\PriceType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

final class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'description',
        'picture',
        'price',
        'price_type',
    ];

    protected $appends = ['picture_url'];

    /** @return Attribute<string|null, never> */
    protected function pictureUrl(): Attribute
    {
        /** @phpstan-ignore return.type */
        return Attribute::get(
            fn (): ?string => $this->picture ? Storage::disk('public')->url($this->picture) : null
        );
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'price_type' => PriceType::class,
        ];
    }
}
