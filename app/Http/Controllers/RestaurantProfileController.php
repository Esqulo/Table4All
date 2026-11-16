<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Menu;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\User;
use App\Support\OpeningHours;
use App\Support\RestaurantHandle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** Public profile of a restaurant at /vempro/{handle}: anyone can see it, signed in or not. */
class RestaurantProfileController extends Controller
{
    public function show(Request $request, string $handle): Response|RedirectResponse
    {
        $canonical = RestaurantHandle::normalize($handle);

        $restaurant = User::where('handle', $canonical)
            ->where('account_type', AccountType::RESTAURANT)
            ->first(['id', 'name', 'logo', 'banner', 'handle', 'profile_public', 'account_type']);

        abort_if($restaurant === null, 404);

        $viewer = $request->user();
        $isOwner = $viewer !== null
            && $viewer->account_type === AccountType::RESTAURANT
            && $viewer->id === $restaurant->id;

        // A hidden profile doesn't exist for anyone but its owner, who sees what visitors would.
        abort_unless($restaurant->profile_public || $isOwner, 404);

        if ($handle !== $canonical) {
            return redirect()->route('restaurant-profile.show', $canonical, 301);
        }

        $hours = RestaurantOpeningHour::where('user_id', $restaurant->id)
            ->orderBy('weekday')
            ->orderBy('opens_at')
            ->get(['weekday', 'opens_at', 'closes_at'])
            ->map(fn (RestaurantOpeningHour $h): array => [
                'weekday' => $h->weekday,
                'opens_at' => substr($h->opens_at, 0, 5),
                'closes_at' => substr($h->closes_at, 0, 5),
            ]);

        $now = now(config('app.business_timezone'));
        $logoUrl = $restaurant->logo ? Storage::disk('public')->url($restaurant->logo) : null;
        $bannerUrl = $restaurant->banner ? Storage::disk('public')->url($restaurant->banner) : null;
        $url = route('restaurant-profile.show', $restaurant->handle);

        return Inertia::render('profile/show', [
            // Only public fields: the owner row also carries email, phone and auth data.
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'handle' => $restaurant->handle,
                'logo_url' => $logoUrl,
                'banner_url' => $bannerUrl,
            ],
            'hours' => $hours,
            // Null when no hours were set: "closed" would be a guess.
            'isOpen' => $hours->isEmpty() ? null : OpeningHours::isOpenAt($hours, $now),
            'today' => $now->dayOfWeek,
            'photos' => RestaurantPhoto::where('user_id', $restaurant->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'path']),
            'menus' => Menu::where('user_id', $restaurant->id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'isOwner' => $isOwner,
            'isPublic' => $restaurant->profile_public,
            'url' => $url,
        ])->withViewData([
            // Link previews (WhatsApp, Instagram, …) read these tags without running JavaScript.
            'meta' => $restaurant->profile_public ? [
                'title' => $restaurant->name,
                'description' => __('venue_profile.meta_description', ['name' => $restaurant->name]),
                'image' => $bannerUrl ?? $logoUrl,
                'large_image' => $bannerUrl !== null,
                'url' => $url,
            ] : null,
        ]);
    }
}
