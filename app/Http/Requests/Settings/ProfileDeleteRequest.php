<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Enums\AccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * A restaurant can't delete itself: its tables, bills and online payments are records the
     * law requires keeping (LGPD Art. 16 I, tax rules) and deleting the account would cascade
     * through all of them. The platform closes it instead (`restaurants:close`), which removes
     * the personal and public data and keeps the records.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->user()?->account_type === AccountType::RESTAURANT) {
                    $validator->errors()->add('password', __('privacy.restaurant_cannot_delete'));
                }
            },
        ];
    }
}
