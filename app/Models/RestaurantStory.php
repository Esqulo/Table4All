<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A "Hoje tem!" story: a picture or a short video the restaurant posts, gone 24 hours later.
 *
 * Videos are converted after the upload (App\Jobs\ProcessStoryVideo): until then the story is
 * `processing` and only its owner sees it. The files are deleted with the story (stories:prune).
 *
 * @property int $id
 * @property int $user_id
 * @property string $media_type
 * @property string|null $path
 * @property string|null $poster_path
 * @property string|null $source_path
 * @property string $status
 * @property int|null $duration_ms
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class RestaurantStory extends Model
{
    public const IMAGE = 'image';

    public const VIDEO = 'video';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** How long a story stays up. */
    public const LIFETIME_HOURS = 24;

    /** Live stories per restaurant: enough for a busy day, bounded for viewers on mobile data. */
    public const MAX_ACTIVE = 20;

    /** Longest video accepted; the conversion also cuts there. */
    public const MAX_VIDEO_SECONDS = 30;

    /** Upload limits in KB: a 30 s phone video in 1080p fits in 50 MB. */
    public const MAX_VIDEO_KB = 51200;

    public const MAX_IMAGE_KB = 10240;

    /** Longest side kept for pictures: a full 1080×1920 story. */
    public const IMAGE_MAX_SIDE = 1920;

    /** Public folder of the converted files. Nothing in it outlives a story by more than the cleanup interval. */
    public const DIRECTORY = 'stories';

    /** Private folder (local disk) of uploaded videos waiting for conversion; never served. */
    public const INCOMING_DIRECTORY = 'stories-incoming';

    protected $fillable = [
        'user_id', 'media_type', 'path', 'poster_path', 'source_path', 'status', 'duration_ms', 'expires_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Not expired yet. Expired stories disappear right away, even before the cleanup deletes them.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    public function url(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }

    public function posterUrl(): ?string
    {
        return $this->poster_path ? Storage::disk('public')->url($this->poster_path) : null;
    }

    /** Deletes the story's files, wherever they are. */
    public function deleteFiles(): void
    {
        $public = array_values(array_filter([$this->path, $this->poster_path]));

        if ($public !== []) {
            Storage::disk('public')->delete($public);
        }

        if ($this->source_path) {
            Storage::disk('local')->delete($this->source_path);
        }
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
