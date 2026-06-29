<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;

class TableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'title'     => ['sometimes', 'required', 'string', 'max:255'],
            'products'  => ['nullable', 'array'],
            'products.*' => ['integer'],
        ];
    }
}
