<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authenticator app is mandatory for admins: Fortify's settings actions that turn it
 * off or replace its secret are refused for them (a lost phone is handled by a recovery
 * code, or on the server with app:make-admin --reset-two-factor).
 */
class ProtectAdminTwoFactor
{
    private const LOCKED_ROUTES = ['two-factor.disable', 'two-factor.enable', 'two-factor.confirm'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->account_type === AccountType::ADMIN
            && in_array($request->route()?->getName(), self::LOCKED_ROUTES, true)) {
            abort(403);
        }

        return $next($request);
    }
}
