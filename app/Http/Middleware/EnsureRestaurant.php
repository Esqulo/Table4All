<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->account_type !== AccountType::RESTAURANT) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
