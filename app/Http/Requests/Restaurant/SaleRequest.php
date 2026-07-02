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
        $type = $this->input('type', 'periodic');

        return [
            'product_id'  => ['required', 'integer', 'exists:products,id'],
            'type'        => ['required', 'in:periodic,scheduled'],
            'sale_price'  => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            // periodic
            'days'        => [$type === 'periodic' ? 'required' : 'nullable', 'array', 'min:1'],
            'days.*'      => ['integer', 'between:0,6'],
            'start_time'  => [$type === 'periodic' ? 'required' : 'nullable', 'date_format:H:i'],
            'end_time'    => [$type === 'periodic' ? 'required' : 'nullable', 'date_format:H:i', 'after:start_time'],
            // scheduled
            'starts_at'   => [$type === 'scheduled' ? 'required' : 'nullable', 'date'],
            'ends_at'     => [$type === 'scheduled' ? 'required' : 'nullable', 'date', 'after:starts_at'],
        ];
    }
}
