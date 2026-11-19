<?php

namespace App\Console\Commands;

use App\Models\RestaurantStory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes expired "Hoje tem!" stories and their files, so they don't pile up on the server.
 * Scheduled every five minutes (routes/console.php).
 *
 * Besides the rows, it sweeps the story folders: a file can be left behind when a process dies
 * between writing it and recording it, or when a restaurant account is deleted (its rows go with
 * a cascade, its files don't). Every story is gone within LIFETIME_HOURS, and a video is converted
 * within the first hour, so any story file older than LIFETIME_HOURS + 1 belongs to no live story.
 */
#[Signature('stories:prune')]
#[Description('Delete expired "Hoje tem!" stories and any leftover story files')]
final class PruneStories extends Command
{
    /** A video still converting after this long is given up on (the job timed out or died). */
    private const STUCK_MINUTES = 60;

    /** Uploads waiting for conversion older than this are leftovers. */
    private const INCOMING_MAX_HOURS = 2;

    public function handle(): int
    {
        $expired = 0;

        RestaurantStory::where('expires_at', '<=', now())
            ->select(['id', 'path', 'poster_path', 'source_path'])
            ->chunkById(200, function ($stories) use (&$expired): void {
                foreach ($stories as $story) {
                    $story->deleteFiles();
                }

                $expired += RestaurantStory::whereIn('id', $stories->modelKeys())->delete();
            });

        $stuck = RestaurantStory::where('status', RestaurantStory::PROCESSING)
            ->where('created_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->select(['id', 'source_path'])
            ->get();

        foreach ($stuck as $story) {
            if ($story->source_path) {
                Storage::disk('local')->delete($story->source_path);
            }

            RestaurantStory::whereKey($story->id)
                ->where('status', RestaurantStory::PROCESSING)
                ->update(['status' => RestaurantStory::FAILED, 'source_path' => null]);
        }

        $orphans = $this->sweep(Storage::disk('public'), RestaurantStory::DIRECTORY, (RestaurantStory::LIFETIME_HOURS + 1) * 3600)
            + $this->sweep(Storage::disk('local'), RestaurantStory::INCOMING_DIRECTORY, self::INCOMING_MAX_HOURS * 3600);

        $this->info("Deleted {$expired} expired story(ies), gave up on {$stuck->count()} stuck video(s), removed {$orphans} leftover file(s).");

        return self::SUCCESS;
    }

    /** Deletes the files under $directory last modified more than $maxAge seconds ago, and the emptied folders. */
    private function sweep(Filesystem $disk, string $directory, int $maxAge): int
    {
        $cutoff = now()->getTimestamp() - $maxAge;
        $deleted = 0;

        foreach ($disk->allFiles($directory) as $file) {
            if ($disk->lastModified($file) < $cutoff && $disk->delete($file)) {
                $deleted++;
            }
        }

        // Work folders of conversions that died midway. Only old ones: a conversion running
        // right now has an empty folder until ffmpeg writes to it. (Both disks are local.)
        foreach ($disk->directories($directory) as $folder) {
            $mtime = @filemtime($disk->path($folder));

            if ($mtime !== false && $mtime < $cutoff && $disk->allFiles($folder) === []) {
                $disk->deleteDirectory($folder);
            }
        }

        return $deleted;
    }
}
