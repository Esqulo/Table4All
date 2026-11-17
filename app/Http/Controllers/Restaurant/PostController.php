<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantPost;
use App\Support\Idempotency;
use App\Support\ImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** The restaurant publishes and removes posts on its public profile. */
class PostController extends Controller
{
    /** Longest side kept: a 1080×1920 story (9:16) must stay at full Instagram size. */
    private const IMAGE_MAX_SIDE = 1920;

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['body' => trim((string) $request->input('body')) ?: null]);

        /** @var array{body: string|null, image_aspect: string|null} $data */
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.RestaurantPost::BODY_MAX, 'required_without:image'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_aspect' => ['nullable', 'required_with:image', Rule::in(array_keys(RestaurantPost::ASPECTS))],
        ], [
            'body.required_without' => __('social.error_post_empty'),
        ]);

        $restaurantId = $request->user()->effectiveRestaurantId();

        // Claimed after validation; the picture is stored only for the first copy of a retried request.
        Idempotency::once($request, 'restaurant-post:'.$restaurantId, function () use ($request, $data, $restaurantId): void {
            /** @var UploadedFile|null $file */
            $file = $request->file('image');
            $path = $file ? ImageOptimizer::storeUpload($file, 'posts', self::IMAGE_MAX_SIDE) : null;

            try {
                RestaurantPost::create([
                    'user_id' => $restaurantId,
                    'body' => $data['body'],
                    'image' => $path,
                    'image_aspect' => $path ? $data['image_aspect'] : null,
                ]);
            } catch (\Throwable $e) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }

                throw $e;
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'social.msg_post_published']);

        return back();
    }

    public function destroy(Request $request, RestaurantPost $post): RedirectResponse
    {
        $user = $request->user();
        abort_unless($post->user_id === $user->effectiveRestaurantId(), 403);

        $post->delete();

        if ($post->image) {
            Storage::disk('public')->delete($post->image);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'social.msg_post_deleted']);

        // Deleted from its own page, which no longer exists: go to the profile.
        // From the profile or the feed, stay where the restaurant was.
        $postPage = route('restaurant-profile.post', [$user->handle, $post->id]);

        if (url()->previous() === $postPage) {
            return to_route('restaurant-profile.show', $user->handle);
        }

        return back(fallback: route('restaurant-profile.show', $user->handle));
    }
}
