<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform admins only, and only once they've set up the authenticator app: until then
 * the setup screen is all they reach (routes marked "setup" let them in for it).
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if ($user?->account_type !== AccountType::ADMIN) {
            abort(403);
        }

        if ($mode !== 'setup' && $user->two_factor_confirmed_at === null) {
            return redirect()->route('admin.two-factor.setup');
        }

        return $next($request);
    }
}
