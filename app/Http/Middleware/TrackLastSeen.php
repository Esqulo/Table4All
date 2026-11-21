<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the signed-in account as seen, for the admin panel's "active users" figures.
 * Live screens poll every few seconds, so it writes at most once every few minutes per
 * account (a conditional update that does nothing in between), and only after the
 * response went out.
 */
class TrackLastSeen
{
    public const EVERY_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $threshold = now()->subMinutes(self::EVERY_MINUTES);

        if ($user->last_seen_at !== null && $user->last_seen_at->greaterThan($threshold)) {
            return;
        }

        DB::table('users')
            ->where('id', $user->id)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $threshold))
            ->update(['last_seen_at' => now()]);
    }
}
