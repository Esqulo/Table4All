<?php

namespace Tests\Feature\Restaurant;

use App\Models\RestaurantStory;
use App\Models\User;
use App\Support\Video\FfmpegVideoTranscoder;
use App\Support\Video\VideoProbe;
use App\Support\Video\VideoTranscoder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeVideoTranscoder;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** "Hoje tem!": stories that disappear after 24 hours, their uploads and their cleanup. */
class StoriesTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private FakeVideoTranscoder $transcoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        Storage::fake('local');
        $this->transcoder = new FakeVideoTranscoder;
        $this->app->instance(VideoTranscoder::class, $this->transcoder);
    }

    /** The start of an MP4 file: enough for fileinfo to call it video/mp4. */
    private function mp4(string $name = 'clip.mp4'): UploadedFile
    {
        $ftyp = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41";

        return UploadedFile::fake()->createWithContent($name, $ftyp.str_repeat("\x00", 2048));
    }

    private function upload(User $restaurant, UploadedFile $file, array $extra = []): TestResponse
    {
        return $this->actingAs($restaurant)
            ->from(route('social.feed'))
            ->post(route('restaurant.stories.store'), ['media' => $file, ...$extra]);
    }

    private function story(User $restaurant, array $attributes = []): RestaurantStory
    {
        return RestaurantStory::create([
            'user_id' => $restaurant->id,
            'media_type' => RestaurantStory::IMAGE,
            'status' => RestaurantStory::READY,
            'path' => 'stories/'.uniqid().'.webp',
            'expires_at' => now()->addHours(RestaurantStory::LIFETIME_HOURS),
            ...$attributes,
        ]);
    }

    /** @return list<int> */
    private function feedStoryIds(User $viewer): array
    {
        $ids = [];

        $this->actingAs($viewer)->get(route('social.feed'))
            ->assertInertia(function (Assert $page) use (&$ids) {
                foreach ($page->toArray()['props']['stories'] as $group) {
                    foreach ($group['stories'] as $story) {
                        $ids[] = $story['id'];
                    }
                }
            });

        return $ids;
    }

    // ── pictures ─────────────────────────────────────────────────────────────

    public function test_restaurant_posts_a_picture_that_is_re_encoded_and_lasts_24_hours(): void
    {
        $restaurant = $this->restaurant();
        $this->freezeTime();

        $this->upload($restaurant, UploadedFile::fake()->image('foto.png', 1500, 2667))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('social.feed'));

        $story = RestaurantStory::sole();
        $this->assertSame($restaurant->id, $story->user_id);
        $this->assertSame(RestaurantStory::IMAGE, $story->media_type);
        $this->assertSame(RestaurantStory::READY, $story->status);
        // The column keeps whole seconds.
        $this->assertEqualsWithDelta(now()->addHours(24)->getTimestamp(), $story->expires_at->getTimestamp(), 1);
        // Our name and our format, never the upload's.
        $this->assertMatchesRegularExpression('#^stories/[A-Za-z0-9]{40}\.(webp|jpg)$#', (string) $story->path);
        Storage::disk('public')->assertExists((string) $story->path);

        [$width, $height] = getimagesizefromstring((string) Storage::disk('public')->get((string) $story->path)) ?: [0, 0];
        $this->assertLessThanOrEqual(RestaurantStory::IMAGE_MAX_SIDE, max($width, $height));
    }

    public function test_picture_metadata_is_dropped(): void
    {
        $restaurant = $this->restaurant();
        // A JPEG carrying a comment segment, like EXIF would: re-encoding keeps only the pixels.
        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        $tagged = substr($jpeg, 0, 2)."\xFF\xFE\x00\x15SECRET-GPS-POSITION".substr($jpeg, 2);

        $this->upload($restaurant, UploadedFile::fake()->createWithContent('foto.jpg', $tagged))->assertSessionHasNoErrors();

        $stored = (string) Storage::disk('public')->get((string) RestaurantStory::sole()->path);
        $this->assertStringNotContainsString('SECRET-GPS-POSITION', $stored);
    }

    /** @return array<string, array{\Closure(): UploadedFile}> */
    public static function rejectedFiles(): array
    {
        return [
            'pdf' => [fn () => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')],
            'text named like a picture' => [fn () => self::realFile('foto.jpg', 'not a picture at all')],
            'svg (can carry scripts)' => [fn () => self::realFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'html named like a video' => [fn () => self::realFile('clip.mp4', '<html><script>alert(1)</script></html>')],
            'playlist named like a video' => [fn () => self::realFile('clip.mp4', "#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:10.0,\nfile:///etc/passwd\n")],
            'gif' => [fn () => UploadedFile::fake()->image('anim.gif', 100, 100)],
            'huge picture' => [fn () => UploadedFile::fake()->image('big.png', 8500, 10)],
            'too big' => [fn () => UploadedFile::fake()->image('foto.jpg', 100, 100)->size(RestaurantStory::MAX_IMAGE_KB + 1)],
        ];
    }

    /** An upload whose type is read from its content, as in a real request (the fakes report it from the name). */
    private static function realFile(string $name, string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param  \Closure(): UploadedFile  $file */
    #[DataProvider('rejectedFiles')]
    public function test_files_that_are_not_a_safe_picture_or_video_are_rejected(\Closure $file): void
    {
        $this->upload($this->restaurant(), $file())->assertSessionHasErrors('media');

        $this->assertSame(0, RestaurantStory::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_is_required(): void
    {
        $this->actingAs($this->restaurant())
            ->post(route('restaurant.stories.store'), [])
            ->assertSessionHasErrors('media');
    }

    // ── videos ───────────────────────────────────────────────────────────────

    public function test_video_is_kept_private_until_converted_then_published(): void
    {
        $restaurant = $this->restaurant();

        // The queue runs synchronously in tests: the conversion happens within the request.
        $this->upload($restaurant, $this->mp4())->assertSessionHasNoErrors();

        $story = RestaurantStory::sole();
        $this->assertSame(RestaurantStory::VIDEO, $story->media_type);
        $this->assertSame(RestaurantStory::READY, $story->status);
        $this->assertNull($story->source_path);
        $this->assertSame(12000, $story->duration_ms);
        $this->assertMatchesRegularExpression('#^stories/[A-Za-z0-9]{40}\.mp4$#', (string) $story->path);
        $this->assertMatchesRegularExpression('#^stories/[A-Za-z0-9]{40}\.jpg$#', (string) $story->poster_path);
        Storage::disk('public')->assertExists([(string) $story->path, (string) $story->poster_path]);

        // The upload went to the private disk, was converted from there, and is gone.
        $this->assertCount(1, $this->transcoder->converted);
        $this->assertStringContainsString(RestaurantStory::INCOMING_DIRECTORY, $this->transcoder->converted[0]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_video_waiting_for_conversion_is_seen_only_by_its_owner(): void
    {
        $restaurant = $this->restaurant();
        $processing = $this->story($restaurant, [
            'media_type' => RestaurantStory::VIDEO,
            'status' => RestaurantStory::PROCESSING,
            'path' => null,
            'source_path' => 'stories-incoming/x',
        ]);
        $failed = $this->story($restaurant, ['media_type' => RestaurantStory::VIDEO, 'status' => RestaurantStory::FAILED, 'path' => null]);

        $this->assertSame([], $this->feedStoryIds($this->customer()));
        $this->assertSame([$processing->id, $failed->id], $this->feedStoryIds($restaurant));

        $this->actingAs($restaurant)->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stories.0.is_owner', true)
                ->where('stories.0.stories.0.status', 'processing')
                ->where('stories.0.stories.0.url', null)
                ->missing('stories.0.stories.0.source_path'));
    }

    public function test_failed_conversion_is_marked_and_leaves_no_files(): void
    {
        $this->transcoder->failConversion = true;

        $this->upload($this->restaurant(), $this->mp4())->assertSessionHasNoErrors();

        $story = RestaurantStory::sole();
        $this->assertSame(RestaurantStory::FAILED, $story->status);
        $this->assertNull($story->path);
        $this->assertNull($story->source_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unreadable_videos_are_rejected_before_storing(): void
    {
        $this->transcoder->probe = null;

        $this->upload($this->restaurant(), $this->mp4())
            ->assertSessionHasErrors(['media' => __('stories.error_video_unreadable')]);

        $this->assertSame(0, RestaurantStory::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_videos_longer_than_the_limit_are_rejected(): void
    {
        $this->transcoder->probe = new VideoProbe('mov', RestaurantStory::MAX_VIDEO_SECONDS + 1, 1080, 1920);

        $this->upload($this->restaurant(), $this->mp4())
            ->assertSessionHasErrors(['media' => __('stories.error_video_too_long', ['seconds' => RestaurantStory::MAX_VIDEO_SECONDS])]);

        $this->assertSame(0, RestaurantStory::count());
    }

    public function test_a_phone_rounding_the_length_is_still_accepted(): void
    {
        $this->transcoder->probe = new VideoProbe('mov', RestaurantStory::MAX_VIDEO_SECONDS + 0.4, 1080, 1920);

        $this->upload($this->restaurant(), $this->mp4())->assertSessionHasNoErrors();

        $this->assertSame(RestaurantStory::MAX_VIDEO_SECONDS * 1000, RestaurantStory::sole()->duration_ms);
    }

    public function test_without_ffmpeg_videos_are_refused_but_pictures_work(): void
    {
        $this->transcoder->available = false;
        $restaurant = $this->restaurant();

        $this->upload($restaurant, $this->mp4())
            ->assertSessionHasErrors(['media' => __('stories.error_video_unavailable')]);
        $this->upload($restaurant, UploadedFile::fake()->image('foto.jpg', 100, 100))->assertSessionHasNoErrors();

        $this->actingAs($restaurant)->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page->where('storyLimits.videoEnabled', false));
    }

    public function test_video_size_limit(): void
    {
        $big = UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x20ftypisom")->size(RestaurantStory::MAX_VIDEO_KB + 1);

        $this->upload($this->restaurant(), $big)->assertSessionHasErrors('media');

        $this->assertSame(0, RestaurantStory::count());
    }

    // ── limits, retries, permissions ─────────────────────────────────────────

    public function test_live_stories_per_restaurant_are_limited(): void
    {
        $restaurant = $this->restaurant();

        for ($i = 0; $i < RestaurantStory::MAX_ACTIVE; $i++) {
            $this->story($restaurant);
        }

        // Expired ones don't count.
        $this->story($restaurant, ['expires_at' => now()->subMinute()]);

        $this->upload($restaurant, UploadedFile::fake()->image('foto.jpg', 100, 100))
            ->assertSessionHasErrors(['media' => __('stories.error_limit', ['max' => RestaurantStory::MAX_ACTIVE])]);

        $this->assertSame(RestaurantStory::MAX_ACTIVE + 1, RestaurantStory::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_retried_upload_is_published_once(): void
    {
        $restaurant = $this->restaurant();

        $this->upload($restaurant, UploadedFile::fake()->image('foto.jpg', 100, 100), ['request_key' => 'abc-123'])->assertSessionHasNoErrors();
        $this->upload($restaurant, UploadedFile::fake()->image('foto.jpg', 100, 100), ['request_key' => 'abc-123'])->assertSessionHasNoErrors();

        $this->assertSame(1, RestaurantStory::count());
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_only_restaurants_post_stories(): void
    {
        $restaurant = $this->restaurant();

        foreach ([$this->waiterOf($restaurant), $this->customer()] as $user) {
            $this->actingAs($user)
                ->post(route('restaurant.stories.store'), ['media' => UploadedFile::fake()->image('foto.jpg', 100, 100)])
                ->assertRedirect(route('dashboard'));
        }

        $this->upload(User::factory()->unverified()->create(['account_type' => 'restaurant']), UploadedFile::fake()->image('f.jpg', 10, 10))
            ->assertRedirect(route('verification.notice'));

        $this->assertSame(0, RestaurantStory::count());

        $this->actingAs($this->customer())->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page->where('storyLimits', null));
    }

    public function test_owner_deletes_a_story_and_its_files(): void
    {
        $restaurant = $this->restaurant();
        Storage::disk('public')->put('stories/a.mp4', 'v');
        Storage::disk('public')->put('stories/a.jpg', 'p');
        $story = $this->story($restaurant, ['media_type' => RestaurantStory::VIDEO, 'path' => 'stories/a.mp4', 'poster_path' => 'stories/a.jpg']);

        $this->actingAs($restaurant)->delete(route('restaurant.stories.destroy', $story))->assertRedirect();

        $this->assertModelMissing($story);
        Storage::disk('public')->assertMissing(['stories/a.mp4', 'stories/a.jpg']);
    }

    public function test_others_cannot_delete_a_story(): void
    {
        $restaurant = $this->restaurant();
        $story = $this->story($restaurant);

        $this->actingAs($this->restaurant())->delete(route('restaurant.stories.destroy', $story))->assertForbidden();
        $this->actingAs($this->waiterOf($restaurant))->delete(route('restaurant.stories.destroy', $story))->assertRedirect(route('dashboard'));
        $this->actingAs($this->customer())->delete(route('restaurant.stories.destroy', $story))->assertRedirect(route('dashboard'));

        $this->assertModelExists($story);
    }

    // ── the feed ─────────────────────────────────────────────────────────────

    public function test_feed_shows_live_stories_of_public_restaurants_newest_restaurant_first(): void
    {
        $older = $this->restaurant(['name' => 'Bar Antigo']);
        $newer = $this->restaurant(['name' => 'Bar Novo']);
        $hidden = $this->restaurant(['profile_public' => false]);
        $first = $this->story($older);
        $second = $this->story($older);
        $latest = $this->story($newer, ['media_type' => RestaurantStory::VIDEO, 'path' => 'stories/v.mp4', 'poster_path' => 'stories/v.jpg', 'duration_ms' => 8000]);
        $this->story($older, ['expires_at' => now()->subSecond()]);
        $this->story($hidden);

        $this->actingAs($this->customer())->get(route('social.feed'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stories', 2)
                ->where('stories.0.restaurant', [
                    'id' => $newer->id,
                    'name' => 'Bar Novo',
                    'handle' => $newer->handle,
                    'logo_url' => null,
                ])
                ->where('stories.0.is_owner', false)
                ->where('stories.0.stories.0.id', $latest->id)
                ->where('stories.0.stories.0.media_type', 'video')
                ->where('stories.0.stories.0.url', Storage::disk('public')->url('stories/v.mp4'))
                ->where('stories.0.stories.0.poster_url', Storage::disk('public')->url('stories/v.jpg'))
                ->where('stories.0.stories.0.duration_ms', 8000)
                ->where('stories.1.restaurant.id', $older->id)
                // Oldest first within a restaurant, like they were posted.
                ->where('stories.1.stories.0.id', $first->id)
                ->where('stories.1.stories.1.id', $second->id)
                ->has('stories.1.stories', 2));
    }

    public function test_a_story_disappears_at_24_hours_even_before_the_cleanup(): void
    {
        $restaurant = $this->restaurant();
        $story = $this->story($restaurant);
        $customer = $this->customer();

        $this->travel(23)->hours();
        $this->assertSame([$story->id], $this->feedStoryIds($customer));

        $this->travel(1)->hours();
        $this->assertSame([], $this->feedStoryIds($customer));
    }

    public function test_the_viewing_restaurant_comes_first_even_with_a_hidden_profile(): void
    {
        $other = $this->restaurant();
        $own = $this->restaurant(['profile_public' => false]);
        $mine = $this->story($own);
        $theirs = $this->story($other);

        $this->assertSame([$mine->id, $theirs->id], $this->feedStoryIds($own));
        $this->assertSame([$theirs->id], $this->feedStoryIds($this->customer()));
    }

    public function test_feed_queries_do_not_grow_with_stories(): void
    {
        $customer = $this->customer();
        $count = function () use ($customer): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->actingAs($customer)->get(route('social.feed'))->assertOk();

            return $queries;
        };

        $restaurant = $this->restaurant();
        $this->story($restaurant);
        $few = $count();

        foreach (range(1, 8) as $i) {
            $more = $this->restaurant();
            $this->story($more);
            $this->story($more);
        }

        $this->assertSame($few, $count());
    }

    // ── cleanup ──────────────────────────────────────────────────────────────

    public function test_cleanup_deletes_expired_stories_and_their_files(): void
    {
        $restaurant = $this->restaurant();
        $public = Storage::disk('public');
        $public->put('stories/old.mp4', 'v');
        $public->put('stories/old.jpg', 'p');
        $public->put('stories/live.webp', 'i');
        $expired = $this->story($restaurant, ['media_type' => RestaurantStory::VIDEO, 'path' => 'stories/old.mp4', 'poster_path' => 'stories/old.jpg', 'expires_at' => now()->subMinute()]);
        $live = $this->story($restaurant, ['path' => 'stories/live.webp']);

        $this->artisan('stories:prune')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($live);
        $public->assertMissing(['stories/old.mp4', 'stories/old.jpg']);
        $public->assertExists('stories/live.webp');
    }

    public function test_cleanup_sweeps_leftover_files_and_gives_up_on_stuck_videos(): void
    {
        $restaurant = $this->restaurant();
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        // A file no story points to (a process died after writing it), past any story's life.
        $public->put('stories/orphan.mp4', 'v');
        touch($public->path('stories/orphan.mp4'), now()->subHours(RestaurantStory::LIFETIME_HOURS + 2)->getTimestamp());
        // A recent file may belong to a story being created right now: kept.
        $public->put('stories/recent.mp4', 'v');
        // A conversion that never finished.
        $local->put('stories-incoming/stuck', 'raw');
        $stuck = $this->story($restaurant, ['media_type' => RestaurantStory::VIDEO, 'status' => RestaurantStory::PROCESSING, 'path' => null, 'source_path' => 'stories-incoming/stuck']);
        RestaurantStory::whereKey($stuck->id)->update(['created_at' => now()->subHours(2)]);
        // An upload left behind with no story at all.
        $local->put('stories-incoming/lost', 'raw');
        touch($local->path('stories-incoming/lost'), now()->subHours(3)->getTimestamp());
        $local->put('stories-incoming/fresh', 'raw');

        $this->artisan('stories:prune')->assertSuccessful();

        $public->assertMissing('stories/orphan.mp4');
        $public->assertExists('stories/recent.mp4');
        $this->assertSame(RestaurantStory::FAILED, $stuck->fresh()?->status);
        $this->assertNull($stuck->fresh()?->source_path);
        $local->assertMissing(['stories-incoming/stuck', 'stories-incoming/lost']);
        $local->assertExists('stories-incoming/fresh');
    }

    public function test_cleanup_runs_every_five_minutes(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'stories:prune'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_deleting_a_restaurant_removes_its_stories_and_the_cleanup_their_files(): void
    {
        $restaurant = $this->restaurant();
        Storage::disk('public')->put('stories/gone.webp', 'i');
        $this->story($restaurant, ['path' => 'stories/gone.webp']);

        $restaurant->delete();
        $this->assertSame(0, RestaurantStory::count());

        $this->artisan('stories:prune')->assertSuccessful();
        // Too recent to be sure it's a leftover.
        Storage::disk('public')->assertExists('stories/gone.webp');

        $this->travel(RestaurantStory::LIFETIME_HOURS + 2)->hours();
        $this->artisan('stories:prune')->assertSuccessful();

        Storage::disk('public')->assertMissing('stories/gone.webp');
    }

    // ── real ffmpeg (skipped where it isn't installed, as in CI) ─────────────

    public function test_ffmpeg_converts_a_phone_video_and_strips_its_metadata(): void
    {
        $ffmpeg = new FfmpegVideoTranscoder;

        if (! $ffmpeg->available()) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }

        $this->app->instance(VideoTranscoder::class, $ffmpeg);
        $source = tempnam(sys_get_temp_dir(), 'story').'.mov';
        // A 3 s 1080×1920 clip with sound, a GPS position and a device name, like a phone records.
        Process::run([
            'ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=1080x1920:rate=30:duration=3',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
            '-metadata', 'location=-23.5505-046.6333/',
            '-metadata', 'com.apple.quicktime.model=iPhone',
            '-c:v', 'libx264', '-c:a', 'aac', '-shortest', $source,
        ])->throw();

        $this->upload($this->restaurant(), new UploadedFile($source, 'IMG_0001.MOV', 'video/quicktime', null, true))
            ->assertSessionHasNoErrors();

        $story = RestaurantStory::sole();
        $this->assertSame(RestaurantStory::READY, $story->status);
        $this->assertEqualsWithDelta(3000, $story->duration_ms, 200);

        $output = Storage::disk('public')->path((string) $story->path);
        $probe = json_decode(Process::run(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $output])->output(), true);
        $video = collect($probe['streams'])->firstWhere('codec_type', 'video');

        $this->assertSame('h264', $video['codec_name']);
        $this->assertSame(720, $video['width']);
        $this->assertSame(1280, $video['height']);
        $this->assertNotNull(collect($probe['streams'])->firstWhere('codec_type', 'audio'));
        $tags = json_encode($probe['format']['tags'] ?? []);
        $this->assertStringNotContainsString('-23.5505', (string) $tags);
        $this->assertStringNotContainsString('iPhone', (string) $tags);
        Storage::disk('public')->assertExists((string) $story->poster_path);
        $this->assertSame([], Storage::disk('local')->allFiles());

        @unlink($source);
    }

    public function test_ffmpeg_refuses_a_playlist_disguised_as_a_video(): void
    {
        $ffmpeg = new FfmpegVideoTranscoder;

        if (! $ffmpeg->available()) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }

        // The classic trick: a playlist that makes ffmpeg read a local file into the "video".
        $file = tempnam(sys_get_temp_dir(), 'story');
        file_put_contents($file, "#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:10.0,\nfile:///etc/passwd\n#EXT-X-ENDLIST\n");

        $this->assertNull($ffmpeg->probe($file));

        @unlink($file);
    }
}
