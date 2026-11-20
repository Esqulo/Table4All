<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\StaffFeature;
use App\Http\Controllers\Restaurant\CallController;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // What the account reaches per feature of its restaurant (none/read/write): the
                // sidebar and the read-only screens follow it; the routes enforce it.
                'permissions' => fn () => $request->user()?->accessMap(),
                'staffRole' => fn () => $request->user()?->isStaff() ? $request->user()->staffRole()->value : null,
                // Tables waiting for someone: the sidebar's badge on the calls board (full page loads only).
                'openCalls' => fn () => $request->user()?->access(StaffFeature::CALLS)->allows(Permission::READ)
                    ? CallController::openCount($request->user()->effectiveRestaurantId())
                    : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Language of this response (SetLocale, from the `locale` cookie): the
            // server render and the browser's hydration both use it, so they match.
            'locale' => app()->getLocale(),
        ];
    }
}
