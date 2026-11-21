<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Enums\AccountType;
use App\Enums\StaffFeature;
use App\Enums\StaffRole;
use App\Models\User;
use App\Models\WaiterInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class WaiterInvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token): Response
    {
        $invitation = WaiterInvitation::pending()
            ->where('token', $token)
            ->firstOrFail();

        $restaurant = User::findOrFail($invitation->restaurant_id, ['id', 'name']);

        return Inertia::render('waiter/accept', [
            'token' => $token,
            'email' => $invitation->email,
            'restaurant' => $restaurant,
            'email_taken' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = WaiterInvitation::pending()
            ->where('token', $token)
            ->firstOrFail();

        if (User::where('email', $invitation->email)->exists()) {
            return back()->withErrors(['email' => __('waiters.error_email_taken')]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // The same strength rules as signing up (stricter in production).
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => $validated['password'],
            'account_type' => AccountType::WAITER,
            'restaurant_id' => $invitation->restaurant_id,
        ]);
        // The invitation link proved the address; the role comes from the invitation and the
        // access per feature starts at the waiter defaults (the restaurant adjusts it later).
        // Not mass-assignable, hence forceFill: passing them to create() silently drops them.
        $user->forceFill([
            'email_verified_at' => now(),
            'staff_role' => StaffRole::tryFrom($invitation->role) ?? StaffRole::WAITER,
            'staff_permissions' => StaffFeature::waiterDefaults(),
        ])->save();

        $invitation->update(['accepted_at' => now()]);

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
