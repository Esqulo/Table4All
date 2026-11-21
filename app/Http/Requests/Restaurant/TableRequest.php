<?php

namespace App\Http\Requests\Restaurant;

use App\Models\QueueItem;
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
            'products' => ['nullable', 'array', 'max:500'],
            'products.*.product_id' => ['required', 'integer'],
            // Only identifies a line already on the bill; new lines are priced by the server.
            'products.*.price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            // Priced by the server (product or promotion); a price sent here is ignored.
            'queue_additions' => ['nullable', 'array', 'max:200'],
            'queue_additions.*.product_id' => ['required', 'integer'],
            'queue_additions.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'queue_additions.*.notes' => ['nullable', 'string', 'max:'.QueueItem::NOTES_MAX],
            'order_version' => ['nullable', 'string', 'max:64'],
            // One-time QR a customer showed; links the new table to them.
            'customer_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
