<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;

class TableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'products' => ['nullable', 'array'],
            'products.*.product_id' => ['required', 'integer'],
            'products.*.price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'queue_additions' => ['nullable', 'array'],
            'queue_additions.*.product_id' => ['required', 'integer'],
            'queue_additions.*.price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'queue_additions.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'order_version' => ['nullable', 'string', 'max:64'],
        ];
    }
}
