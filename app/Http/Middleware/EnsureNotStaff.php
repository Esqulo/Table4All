<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Restaurant and waiter accounts never join a table as a customer. */
class EnsureNotStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $type = $request->user()?->account_type;

        if ($type === AccountType::RESTAURANT || $type === AccountType::WAITER) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'join_table.staff_cannot_join']);

            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
