<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\OpeningHoursRequest;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\User;
use App\Support\ImageOptimizer;
use App\Support\RestaurantHandle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Venue settings of the restaurant: public profile, opening hours, photos, logo and banner. */
class EstablishmentController extends Controller
{
    /** Logos are shown small (menu header, sidebar); 512 px covers 3x screens. */
    private const LOGO_MAX_SIDE = 512;

    /** The banner spans the whole profile header, even on a desktop screen. */
    private const BANNER_MAX_SIDE = 1920;

    /** Venue photos can be shown full width on a phone. */
    private const PHOTO_MAX_SIDE = 1600;

    /** Tables a venue can declare: well past any real dining room, bounded for the crowd level. */
    private const MAX_TABLE_CAPACITY = 500;

    /** An https address on iFood's own domain, so the profile can't link anywhere else. */
    private const IFOOD_URL_PATTERN = '#^https://([a-z0-9-]+\.)*ifood\.com\.br(/[^\s]*)?$#i';

    public function edit(Request $request): Response
    {
        $restaurantId = $request->user()->effectiveRestaurantId();

        return Inertia::render('restaurant/establishment', [
            'logoUrl' => $request->user()->logo_url,
            'bannerUrl' => $request->user()->banner_url,
            'handle' => $request->user()->handle,
            'profilePublic' => $request->user()->profile_public,
            'address' => $request->user()->address,
            'ifoodUrl' => $request->user()->ifood_url,
            'priceLevel' => $request->user()->price_level,
            'tableCapacity' => $request->user()->table_capacity,
            // Whether the address was found on the map ("closest first" on the discovery page).
            'addressLocated' => $request->user()->latitude !== null,
            'profileBaseUrl' => url('vempro'),
            'hours' => RestaurantOpeningHour::where('user_id', $restaurantId)
                ->orderBy('weekday')
                ->orderBy('opens_at')
                ->get(['weekday', 'opens_at', 'closes_at'])
                ->map(fn (RestaurantOpeningHour $h): array => [
                    'weekday' => $h->weekday,
                    'opens_at' => substr($h->opens_at, 0, 5),
                    'closes_at' => substr($h->closes_at, 0, 5),
                ]),
            'photos' => RestaurantPhoto::where('user_id', $restaurantId)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'path', 'position']),
            'maxPhotos' => RestaurantPhoto::MAX_PER_RESTAURANT,
        ]);
    }

    /**
     * The @handle of the public profile (/vempro/{handle}), whether the profile is visible,
     * its address and iFood link, and what the discovery page filters by (price level and
     * how many tables the venue has). Those are left alone when a request omits them
     * (the "make public" shortcut on the profile page only sends handle and visibility).
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['handle' => RestaurantHandle::normalize((string) $request->input('handle'))]);

        $ifood = $request->input('ifood_url');

        // People paste "ifood.com.br/delivery/…" without the scheme.
        if (is_string($ifood) && $ifood !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $ifood)) {
            $request->merge(['ifood_url' => 'https://'.$ifood]);
        }

        /** @var array{handle: string, profile_public: bool, address?: string|null, ifood_url?: string|null, price_level?: int|null, table_capacity?: int|null} $data */
        $data = $request->validate([
            'handle' => [
                'required',
                'string',
                'min:'.RestaurantHandle::MIN,
                'max:'.RestaurantHandle::MAX,
                'regex:'.RestaurantHandle::PATTERN,
                Rule::unique('users', 'handle')->ignore($user->id),
            ],
            'profile_public' => ['required', 'boolean'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ifood_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:https', 'regex:'.self::IFOOD_URL_PATTERN],
            'price_level' => ['sometimes', 'nullable', 'integer', 'between:1,3'],
            'table_capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_TABLE_CAPACITY],
        ], [
            'handle.regex' => __('establishment.error_handle_format'),
            'handle.unique' => __('establishment.error_handle_taken'),
            'ifood_url.url' => __('establishment.error_ifood_url'),
            'ifood_url.regex' => __('establishment.error_ifood_url'),
        ]);

        try {
            $user->update($data);
        } catch (UniqueConstraintViolationException) {
            // Another restaurant took the same handle between validation and the write.
            throw ValidationException::withMessages([
                'handle' => __('establishment.error_handle_taken'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_profile_saved']);

        return back();
    }

    public function updateHours(OpeningHoursRequest $request): RedirectResponse
    {
        $restaurantId = $request->user()->effectiveRestaurantId();
        $now = now();
        $rows = array_map(fn (array $h): array => [
            ...$h,
            'user_id' => $restaurantId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $request->intervals());

        // Delete + insert in one transaction: a retried save can't leave the week half-written.
        DB::transaction(function () use ($restaurantId, $rows): void {
            RestaurantOpeningHour::where('user_id', $restaurantId)->delete();

            if ($rows !== []) {
                RestaurantOpeningHour::insert($rows);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_hours_saved']);

        return back();
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('logo');
        $this->replaceImage($request->user(), 'logo', ImageOptimizer::storeUpload($file, 'logos', self::LOGO_MAX_SIDE));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_logo_saved']);

        return back();
    }

    public function destroyLogo(Request $request): RedirectResponse
    {
        $this->replaceImage($request->user(), 'logo', null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_logo_removed']);

        return back();
    }

    public function updateBanner(Request $request): RedirectResponse
    {
        $request->validate([
            'banner' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('banner');
        $this->replaceImage($request->user(), 'banner', ImageOptimizer::storeUpload($file, 'banners', self::BANNER_MAX_SIDE));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_banner_saved']);

        return back();
    }

    public function destroyBanner(Request $request): RedirectResponse
    {
        $this->replaceImage($request->user(), 'banner', null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_banner_removed']);

        return back();
    }

    public function storePhotos(Request $request): RedirectResponse
    {
        $max = RestaurantPhoto::MAX_PER_RESTAURANT;

        $request->validate([
            'photos' => ['required', 'array', 'min:1', "max:$max"],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $restaurantId = $request->user()->effectiveRestaurantId();

        /** @var list<UploadedFile> $files */
        $files = $request->file('photos');
        $paths = array_map(
            fn (UploadedFile $file): string => ImageOptimizer::storeUpload($file, 'venue', self::PHOTO_MAX_SIDE),
            $files,
        );

        try {
            DB::transaction(function () use ($restaurantId, $paths, $max): void {
                // Lock the owner row so two uploads at once can't both pass the limit.
                User::whereKey($restaurantId)->lockForUpdate()->first();

                $count = RestaurantPhoto::where('user_id', $restaurantId)->count();

                if ($count + count($paths) > $max) {
                    throw ValidationException::withMessages([
                        'photos' => __('establishment.error_photos_limit', ['max' => $max]),
                    ]);
                }

                $next = $count === 0
                    ? 0
                    : (int) RestaurantPhoto::where('user_id', $restaurantId)->max('position') + 1;
                $now = now();
                $rows = [];

                foreach ($paths as $i => $path) {
                    $rows[] = [
                        'user_id' => $restaurantId,
                        'path' => $path,
                        'position' => $next + $i,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                RestaurantPhoto::insert($rows);
            });
        } catch (\Throwable $e) {
            // Nothing was recorded: don't leave orphan files behind.
            Storage::disk('public')->delete($paths);

            throw $e;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_photos_added']);

        return back();
    }

    public function reorderPhotos(Request $request): RedirectResponse
    {
        $restaurantId = $request->user()->effectiveRestaurantId();
        $ownIds = RestaurantPhoto::where('user_id', $restaurantId)->pluck('id')->all();

        /** @var array{ids: list<int|string>} $data */
        $data = $request->validate([
            'ids' => ['required', 'array', 'size:'.count($ownIds)],
            'ids.*' => ['integer', 'distinct', Rule::in($ownIds)],
        ]);

        DB::transaction(function () use ($data, $restaurantId): void {
            foreach ($data['ids'] as $position => $id) {
                RestaurantPhoto::whereKey((int) $id)
                    ->where('user_id', $restaurantId)
                    ->update(['position' => $position]);
            }
        });

        return back();
    }

    public function destroyPhoto(Request $request, RestaurantPhoto $photo): RedirectResponse
    {
        abort_unless($photo->user_id === $request->user()->effectiveRestaurantId(), 403);

        $photo->delete();
        Storage::disk('public')->delete($photo->path);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_photo_removed']);

        return back();
    }

    /**
     * Points a single-image column (logo, banner) at a new file, or clears it,
     * and deletes the file it replaced.
     *
     * @param  'logo'|'banner'  $column
     */
    private function replaceImage(User $user, string $column, ?string $path): void
    {
        $old = $user->{$column};

        if ($old === null && $path === null) {
            return;
        }

        $user->update([$column => $path]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }
    }
}
