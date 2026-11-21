<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Concerns\PasswordValidationRules;
use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting an admin invitation: the new admin chooses name and password, and goes
 * straight to setting up the authenticator app (required before anything else).
 */
class InvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token): Response
    {
        $invitation = AdminInvitation::findPending($token);

        return Inertia::render('admin/auth/accept-invitation', [
            'token' => $token,
            'email' => $invitation?->email,
            'valid' => $invitation !== null && ! User::where('email', $invitation->email)->exists(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function () use ($token, $data) {
            $invitation = AdminInvitation::pending()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['name' => __('admin.error_invitation_invalid')]);
            }

            $user = new User([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'account_type' => AccountType::ADMIN,
            ]);
            // The invitation reached this address: it's confirmed.
            $user->forceFill(['email_verified_at' => now()])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return to_route('admin.two-factor.setup');
    }
}
