<?php

namespace App\Jobs;

use App\Models\RestaurantStory;
use App\Support\Video\VideoTranscoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Converts an uploaded "Hoje tem!" video into the small MP4 people watch, with a poster frame,
 * then deletes the upload. Converting takes seconds to a minute, so it runs on the queue and
 * the restaurant isn't kept waiting on a slow connection.
 */
final class ProcessStoryVideo implements ShouldQueue
{
    use Queueable;

    /** A video that failed once fails again: the restaurant is told and can post another. */
    public int $tries = 1;

    /** Above the ffmpeg timeouts (FfmpegVideoTranscoder), so those fire first. */
    public int $timeout = 330;

    public function __construct(public readonly int $storyId) {}

    public function handle(VideoTranscoder $transcoder): void
    {
        $story = RestaurantStory::find($this->storyId);

        if ($story === null || $story->status !== RestaurantStory::PROCESSING || $story->source_path === null) {
            return;
        }

        $local = Storage::disk('local');
        $source = $story->source_path;
        $work = $local->path(RestaurantStory::INCOMING_DIRECTORY.'/work-'.Str::random(20));
        File::ensureDirectoryExists($work);
        $stored = [];

        try {
            $probe = $transcoder->probe($local->path($source));

            if ($probe === null) {
                throw new \RuntimeException("Story {$story->id}: the upload is not a readable video.");
            }

            $durationMs = $transcoder->transcode(
                $local->path($source),
                $probe,
                $work.'/video.mp4',
                $work.'/poster.jpg',
                RestaurantStory::MAX_VIDEO_SECONDS,
            );

            $base = RestaurantStory::DIRECTORY.'/'.Str::random(40);
            $stored = [$base.'.mp4', $base.'.jpg'];
            $public = Storage::disk('public');
            $public->putFileAs(dirname($stored[0]), $work.'/video.mp4', basename($stored[0]));
            $public->putFileAs(dirname($stored[1]), $work.'/poster.jpg', basename($stored[1]));

            // Conditional: the restaurant may have deleted the story meanwhile.
            $updated = RestaurantStory::whereKey($story->id)
                ->where('status', RestaurantStory::PROCESSING)
                ->update([
                    'status' => RestaurantStory::READY,
                    'path' => $stored[0],
                    'poster_path' => $stored[1],
                    'source_path' => null,
                    'duration_ms' => $durationMs,
                ]);

            if ($updated === 0) {
                $public->delete($stored);
            }
        } catch (\Throwable $e) {
            if ($stored !== []) {
                Storage::disk('public')->delete($stored);
            }

            self::markFailed($story->id);
            report($e);
        } finally {
            $local->delete($source);
            File::deleteDirectory($work);
        }
    }

    /** Also called when the worker kills the job (timeout): the upload must not linger. */
    public function failed(?\Throwable $e): void
    {
        $story = RestaurantStory::find($this->storyId);

        if ($story?->source_path) {
            Storage::disk('local')->delete($story->source_path);
        }

        self::markFailed($this->storyId);
    }

    private static function markFailed(int $storyId): void
    {
        RestaurantStory::whereKey($storyId)
            ->where('status', RestaurantStory::PROCESSING)
            ->update(['status' => RestaurantStory::FAILED, 'source_path' => null]);
    }
}
