<?php

namespace App\Http\Requests\Restaurant;

use App\Enums\PriceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'category_id' => $this->category_id !== '' ? $this->category_id : null,
            'queue_id' => $this->queue_id !== '' ? $this->queue_id : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            // Only the restaurant's own queues: otherwise its orders would show up in another kitchen.
            'queue_id' => ['nullable', 'integer', Rule::exists('restaurant_queues', 'id')->where('user_id', $this->user()?->effectiveRestaurantId())],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'price_type' => ['required', new Enum(PriceType::class)],
            'picture' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
