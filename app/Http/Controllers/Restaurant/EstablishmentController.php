<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\OpeningHoursRequest;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\User;
use App\Support\ImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Venue settings of the restaurant: opening hours, photos and logo. */
class EstablishmentController extends Controller
{
    /** Logos are shown small (menu header, sidebar); 512 px covers 3x screens. */
    private const LOGO_MAX_SIDE = 512;

    /** Venue photos can be shown full width on a phone. */
    private const PHOTO_MAX_SIDE = 1600;

    public function edit(Request $request): Response
    {
        $restaurantId = $request->user()->effectiveRestaurantId();

        return Inertia::render('restaurant/establishment', [
            'logoUrl' => $request->user()->logo_url,
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
        $user = $request->user();
        $old = $user->logo;
        $user->update(['logo' => ImageOptimizer::storeUpload($file, 'logos', self::LOGO_MAX_SIDE)]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_logo_saved']);

        return back();
    }

    public function destroyLogo(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->logo) {
            Storage::disk('public')->delete($user->logo);
            $user->update(['logo' => null]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'establishment.msg_logo_removed']);

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
}
