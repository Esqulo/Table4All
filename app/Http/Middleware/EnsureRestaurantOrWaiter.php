<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantOrWaiter
{
    public function handle(Request $request, Closure $next): Response
    {
        $type = $request->user()?->account_type;

        if ($type !== AccountType::RESTAURANT && $type !== AccountType::WAITER) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
