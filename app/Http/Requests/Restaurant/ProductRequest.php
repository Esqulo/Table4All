<?php

namespace App\Http\Requests\Restaurant;

use App\Enums\PriceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'queue_id' => ['nullable', 'integer', 'exists:restaurant_queues,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'price_type' => ['required', new Enum(PriceType::class)],
            'picture' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
