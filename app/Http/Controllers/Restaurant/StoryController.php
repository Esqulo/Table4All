<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStoryVideo;
use App\Models\RestaurantStory;
use App\Models\User;
use App\Support\Idempotency;
use App\Support\ImageOptimizer;
use App\Support\Video\VideoTranscoder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The restaurant posts and removes its "Hoje tem!" stories: a picture or a short video,
 * gone 24 hours later (stories:prune deletes the rows and the files).
 *
 * Uploads are never served as they came: pictures are re-encoded here, videos are kept
 * on the private disk until ProcessStoryVideo converts them.
 */
class StoryController extends Controller
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * fileinfo's names for phone and browser recordings (some MP4 brands come out as application/mp4).
     * Only a first filter: ffprobe decides what is really accepted (FfmpegVideoTranscoder::probe()).
     */
    private const VIDEO_TYPES = ['video/mp4', 'application/mp4', 'video/quicktime', 'video/webm', 'video/3gpp'];

    public function store(Request $request, VideoTranscoder $transcoder): RedirectResponse
    {
        // The type is read from the file's content (fileinfo), not from its name or the browser.
        $request->validate([
            'media' => [
                'required',
                'file',
                'mimetypes:'.implode(',', [...self::IMAGE_TYPES, ...self::VIDEO_TYPES]),
                'max:'.RestaurantStory::MAX_VIDEO_KB,
            ],
        ], [
            'media.mimetypes' => __('stories.error_type'),
            'media.max' => __('stories.error_too_big', ['mb' => RestaurantStory::MAX_VIDEO_KB / 1024]),
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('media');
        $isVideo = in_array($file->getMimeType(), self::VIDEO_TYPES, true);

        $restaurantId = $request->user()->effectiveRestaurantId();

        // Cheap early answer; create() checks again under a lock.
        if (RestaurantStory::where('user_id', $restaurantId)->live()->count() >= RestaurantStory::MAX_ACTIVE) {
            throw ValidationException::withMessages([
                'media' => __('stories.error_limit', ['max' => RestaurantStory::MAX_ACTIVE]),
            ]);
        }

        if ($isVideo) {
            $this->checkVideo($file, $transcoder);
        } else {
            $request->validate([
                // Bounds the memory GD needs to decode it (a small file can unpack into a huge bitmap).
                'media' => ['image', 'max:'.RestaurantStory::MAX_IMAGE_KB, 'dimensions:max_width=8000,max_height=8000'],
            ], [
                'media.max' => __('stories.error_too_big', ['mb' => RestaurantStory::MAX_IMAGE_KB / 1024]),
                'media.dimensions' => __('stories.error_image_unreadable'),
            ]);
        }

        Idempotency::once($request, 'restaurant-story:'.$restaurantId, function () use ($file, $isVideo, $restaurantId): void {
            $isVideo ? $this->storeVideo($file, $restaurantId) : $this->storeImage($file, $restaurantId);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isVideo ? 'stories.msg_video_processing' : 'stories.msg_published',
        ]);

        return back();
    }

    public function destroy(Request $request, RestaurantStory $story): RedirectResponse
    {
        abort_unless($story->user_id === $request->user()->effectiveRestaurantId(), 403);

        $story->delete();
        $story->deleteFiles();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'stories.msg_deleted']);

        return back();
    }

    /** Rejects what isn't a video we can convert, before anything is stored. */
    private function checkVideo(UploadedFile $file, VideoTranscoder $transcoder): void
    {
        if (! $transcoder->available()) {
            throw ValidationException::withMessages(['media' => __('stories.error_video_unavailable')]);
        }

        $probe = $transcoder->probe((string) $file->getRealPath());

        if ($probe === null) {
            throw ValidationException::withMessages(['media' => __('stories.error_video_unreadable')]);
        }

        // Half a second of slack: phones round the length they show.
        if ($probe->seconds > RestaurantStory::MAX_VIDEO_SECONDS + 0.5) {
            throw ValidationException::withMessages([
                'media' => __('stories.error_video_too_long', ['seconds' => RestaurantStory::MAX_VIDEO_SECONDS]),
            ]);
        }
    }

    private function storeImage(UploadedFile $file, int $restaurantId): void
    {
        $path = ImageOptimizer::storeReencoded($file, RestaurantStory::DIRECTORY, RestaurantStory::IMAGE_MAX_SIDE);

        if ($path === null) {
            throw ValidationException::withMessages(['media' => __('stories.error_image_unreadable')]);
        }

        $this->create($restaurantId, [
            'media_type' => RestaurantStory::IMAGE,
            'status' => RestaurantStory::READY,
            'path' => $path,
        ], fn () => Storage::disk('public')->delete($path));
    }

    private function storeVideo(UploadedFile $file, int $restaurantId): void
    {
        // Private disk, our own name and no extension from the client: never reachable by URL.
        $source = $file->storeAs(RestaurantStory::INCOMING_DIRECTORY, Str::random(40), 'local');

        if ($source === false) {
            throw new \RuntimeException('Could not store the uploaded video.');
        }

        $story = $this->create($restaurantId, [
            'media_type' => RestaurantStory::VIDEO,
            'status' => RestaurantStory::PROCESSING,
            'source_path' => $source,
        ], fn () => Storage::disk('local')->delete($source));

        ProcessStoryVideo::dispatch($story->id)->afterCommit();
    }

    /**
     * Creates the story unless the restaurant reached the limit of live stories.
     * $cleanup removes the stored file when nothing was recorded.
     *
     * @param  array<string, mixed>  $attributes
     * @param  callable(): mixed  $cleanup
     */
    private function create(int $restaurantId, array $attributes, callable $cleanup): RestaurantStory
    {
        try {
            return DB::transaction(function () use ($restaurantId, $attributes): RestaurantStory {
                // Lock the owner row so two uploads at once can't both pass the limit.
                User::whereKey($restaurantId)->lockForUpdate()->first();

                if (RestaurantStory::where('user_id', $restaurantId)->live()->count() >= RestaurantStory::MAX_ACTIVE) {
                    throw ValidationException::withMessages([
                        'media' => __('stories.error_limit', ['max' => RestaurantStory::MAX_ACTIVE]),
                    ]);
                }

                return RestaurantStory::create([
                    ...$attributes,
                    'user_id' => $restaurantId,
                    'expires_at' => now()->addHours(RestaurantStory::LIFETIME_HOURS),
                ]);
            });
        } catch (\Throwable $e) {
            $cleanup();

            throw $e;
        }
    }
}
