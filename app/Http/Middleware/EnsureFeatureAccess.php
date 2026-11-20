<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\StaffFeature;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the restaurant owner and the staff members granted the feature through
 * (User::access()). Reading a page needs read access; anything that changes data,
 * and the create/edit forms, need write access.
 *
 * Usage: EnsureFeatureAccess::class.':tables', or ':queues|delivery' when either will do.
 */
class EnsureFeatureAccess
{
    private const READABLE_EDIT_PAGES = ['restaurant.establishment.edit'];

    public function handle(Request $request, Closure $next, string $features): Response
    {
        $user = $request->user();
        $needed = $this->needed($request);

        foreach (explode('|', $features) as $feature) {
            if ($user !== null && $user->access(StaffFeature::from($feature))->allows($needed)) {
                return $next($request);
            }
        }

        // A page the account can't open: back to its home, as before permissions existed.
        if ($request->isMethodSafe()) {
            return redirect()->route('dashboard');
        }

        // A change it may not make (read-only, or access taken away while the screen was open).
        Inertia::flash('toast', ['type' => 'error', 'message' => 'staff.error_no_permission']);

        return back(fallback: route('dashboard'));
    }

    private function needed(Request $request): Permission
    {
        if (! $request->isMethodSafe()) {
            return Permission::WRITE;
        }

        $name = (string) $request->route()?->getName();

        // The venue settings page is named "edit" but is where staff can look at it.
        if (in_array($name, self::READABLE_EDIT_PAGES, true)) {
            return Permission::READ;
        }

        return str_ends_with($name, '.create') || str_ends_with($name, '.edit')
            ? Permission::WRITE
            : Permission::READ;
    }
}
