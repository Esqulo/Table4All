<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Gives an account the platform admin role (the admin panel at /admin). There is no
 * screen for it on purpose: only someone with server access can create admins.
 * Creates the account when the e-mail has none, with a password printed once.
 * At the first sign-in (/admin/entrar) the admin must set up the authenticator app.
 * With --reset-two-factor it removes an admin's authenticator (lost phone and codes).
 */
#[Signature('app:make-admin {email : E-mail of the account} {--name=Admin : Name, when the account is created} {--reset-two-factor : Remove the admin authenticator so it is set up again at the next sign-in}')]
#[Description('Make an account a platform admin (creating it if needed)')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Invalid e-mail.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $password = Str::password(20);
            $user = new User([
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => $password,
                'account_type' => AccountType::ADMIN,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->info("Admin account created for {$email}.");
            $this->line("Password: {$password}");
            $this->line('Sign in at /admin/entrar and set up the authenticator app.');

            return self::SUCCESS;
        }

        if ($user->account_type === AccountType::ADMIN && $this->option('reset-two-factor')) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            $this->info("Authenticator removed for {$email}: it will be set up again at the next sign-in.");

            return self::SUCCESS;
        }

        if ($user->account_type === AccountType::ADMIN) {
            $this->info("{$email} is already an admin.");

            return self::SUCCESS;
        }

        if ($user->account_type !== AccountType::CUSTOMER) {
            // A restaurant or staff account owns data (tables, products, a team): turning it
            // into an admin would leave all of that behind it.
            $this->error("{$email} is a {$user->account_type->value} account. Use another e-mail for the admin.");

            return self::FAILURE;
        }

        $user->forceFill([
            'account_type' => AccountType::ADMIN,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->info("{$email} is now an admin.");

        return self::SUCCESS;
    }
}
