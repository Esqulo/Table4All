<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

/**
 * Setting up the authenticator app, required before an admin reaches anything else
 * (EnsureAdmin). Uses Fortify's two-factor storage, so the codes are the same ones its
 * challenge would accept.
 */
class TwoFactorSetupController extends Controller
{
    public function show(Request $request, EnableTwoFactorAuthentication $enable): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->two_factor_confirmed_at !== null) {
            return to_route('admin.overview');
        }

        // The secret is created once and kept until confirmed, so reloading shows the same QR code.
        if ($user->two_factor_secret === null) {
            $enable($user);
            $user->refresh();
        }

        return Inertia::render('admin/auth/two-factor-setup', [
            'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
            'secret' => Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret),
        ]);
    }

    public function store(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $user = $request->user();

        if ($user->two_factor_confirmed_at !== null) {
            return to_route('admin.overview');
        }

        $data = $request->validate(['code' => ['required', 'string']]);

        try {
            $confirm($user, $data['code']);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['code' => __('admin.error_invalid_code')]);
        }

        // Shown once, right after setup: they get the admin back in if the phone is lost.
        $request->session()->flash('admin_recovery_codes', $user->refresh()->recoveryCodes());

        return to_route('admin.two-factor.recovery-codes');
    }

    public function recoveryCodes(Request $request): Response|RedirectResponse
    {
        $codes = $request->session()->get('admin_recovery_codes');

        if (! is_array($codes)) {
            return to_route('admin.overview');
        }

        return Inertia::render('admin/auth/recovery-codes', ['codes' => $codes]);
    }
}
