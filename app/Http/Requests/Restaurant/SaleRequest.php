<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;

class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'sale_price' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'starts_at'  => ['required', 'date'],
            'ends_at'    => ['required', 'date', 'after:starts_at'],
        ];
    }
}
