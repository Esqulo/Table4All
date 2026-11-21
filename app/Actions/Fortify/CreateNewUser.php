<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $phone = trim((string) ($input['phone'] ?? ''));
        $input['phone'] = $phone === '' ? null : $this->normalizePhone($phone);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            // Agreeing to the terms and privacy policy, and declaring the minimum age (LGPD Art. 14).
            'terms' => ['accepted'],
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'account_type' => 'customer',
            'password' => $input['password'],
        ]);

        $user->recordTermsAcceptance();

        return $user;
    }
}
