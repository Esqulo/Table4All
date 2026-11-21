<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * The admins' own sign-in, apart from everyone else's (the regular /login refuses admin
 * accounts): e-mail and password, then a code from the authenticator app (Google
 * Authenticator or any TOTP app) or one of the recovery codes. Never "remember me".
 *
 * An admin who hasn't set up the authenticator yet (just created) signs in with the
 * password alone and can only reach the setup screen until it's done (EnsureAdmin).
 */
class LoginController extends Controller
{
    /** Session key of the admin who got the password right and still owes the code. */
    private const PENDING = 'admin_login.id';

    private const PENDING_AT = 'admin_login.at';

    /** Time to type the code after the password. */
    private const CODE_MINUTES = 5;

    private const ATTEMPTS_PER_MINUTE = 5;

    public function show(): Response
    {
        return Inertia::render('admin/auth/login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'admin-login:'.Str::transliterate(Str::lower($data['email'])).'|'.$request->ip();
        $this->throttle($key, 'email');

        $user = User::where('email', Str::lower($data['email']))->first();

        // Same answer whether the account doesn't exist, isn't an admin or the password is wrong.
        if ($user === null || $user->account_type !== AccountType::ADMIN || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        if ($user->two_factor_confirmed_at === null) {
            $this->signIn($request, $user);

            return to_route('admin.two-factor.setup');
        }

        $request->session()->put([self::PENDING => $user->id, self::PENDING_AT => now()->getTimestamp()]);

        return to_route('admin.login.code');
    }

    public function showCode(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return to_route('admin.login');
        }

        return Inertia::render('admin/auth/two-factor');
    }

    public function verifyCode(Request $request, TwoFactorAuthenticationProvider $provider): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return to_route('admin.login');
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $key = 'admin-2fa:'.$user->id;
        $field = filled($data['code'] ?? null) ? 'code' : 'recovery_code';
        $this->throttle($key, $field);

        if (! $this->validCode($user, $provider, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([$field => __('admin.error_invalid_code')]);
        }

        RateLimiter::clear($key);
        $request->session()->forget([self::PENDING, self::PENDING_AT]);
        $this->signIn($request, $user);

        return redirect()->intended(route('admin.overview'));
    }

    private function validCode(User $user, TwoFactorAuthenticationProvider $provider, ?string $code, ?string $recovery): bool
    {
        if (filled($code)) {
            return $provider->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code);
        }

        $match = collect($user->recoveryCodes())->first(fn (string $stored) => hash_equals($stored, (string) $recovery));

        if ($match === null) {
            return false;
        }

        // Each recovery code works once.
        $user->replaceRecoveryCode($match);

        return true;
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING);
        $at = (int) $request->session()->get(self::PENDING_AT, 0);

        if ($id === null || now()->getTimestamp() - $at > self::CODE_MINUTES * 60) {
            $request->session()->forget([self::PENDING, self::PENDING_AT]);

            return null;
        }

        $user = User::whereKey((int) $id)->first();

        return $user !== null && $user->account_type === AccountType::ADMIN && $user->two_factor_confirmed_at !== null
            ? $user
            : null;
    }

    private function signIn(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
    }

    private function throttle(string $key, string $field): void
    {
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            throw ValidationException::withMessages([
                $field => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
            ]);
        }
    }
}
