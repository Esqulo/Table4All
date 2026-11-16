<?php

namespace Tests\Feature\Restaurant;

use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Venue settings: opening hours, photos and logo. */
class EstablishmentTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    private function saveHours(User $restaurant, array $hours): TestResponse
    {
        return $this->actingAs($restaurant)
            ->from(route('restaurant.establishment.edit'))
            ->put(route('restaurant.establishment.hours.update'), ['hours' => $hours]);
    }

    /** A real 1x1 PNG, so uploads don't depend on the GD extension. */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));
    }

    private function photo(User $restaurant, int $position = 0): RestaurantPhoto
    {
        $path = "venue/{$restaurant->id}-{$position}.webp";
        Storage::disk('public')->put($path, 'x');

        return RestaurantPhoto::create(['user_id' => $restaurant->id, 'path' => $path, 'position' => $position]);
    }

    // ── access ───────────────────────────────────────────────────────────────

    public function test_restaurant_sees_its_own_settings_only(): void
    {
        $restaurant = $this->restaurant();
        $other = $this->restaurant();
        RestaurantOpeningHour::create(['user_id' => $restaurant->id, 'weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00']);
        RestaurantOpeningHour::create(['user_id' => $other->id, 'weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '15:00']);
        $this->photo($other);

        $this->actingAs($restaurant)
            ->get(route('restaurant.establishment.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('restaurant/establishment')
                ->has('hours', 1)
                ->where('hours.0', ['weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00'])
                ->has('photos', 0)
                ->where('maxPhotos', RestaurantPhoto::MAX_PER_RESTAURANT));
    }

    public function test_waiter_cannot_reach_or_change_the_settings(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);

        $this->actingAs($waiter)->get(route('restaurant.establishment.edit'))->assertRedirect(route('dashboard'));
        $this->actingAs($waiter)
            ->put(route('restaurant.establishment.hours.update'), ['hours' => []])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, RestaurantOpeningHour::count());
    }

    // ── opening hours ────────────────────────────────────────────────────────

    public function test_hours_replace_the_whole_week(): void
    {
        $restaurant = $this->restaurant();
        RestaurantOpeningHour::create(['user_id' => $restaurant->id, 'weekday' => 0, 'opens_at' => '10:00', 'closes_at' => '14:00']);

        $this->saveHours($restaurant, [
            ['weekday' => 2, 'opens_at' => '18:00', 'closes_at' => '23:00'],
            ['weekday' => 2, 'opens_at' => '11:30', 'closes_at' => '15:00'],
            ['weekday' => 6, 'opens_at' => '18:00', 'closes_at' => '02:00'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('restaurant.establishment.edit'));

        $saved = RestaurantOpeningHour::where('user_id', $restaurant->id)
            ->orderBy('id')
            ->get(['weekday', 'opens_at', 'closes_at'])
            ->map(fn ($h) => [$h->weekday, substr($h->opens_at, 0, 5), substr($h->closes_at, 0, 5)])
            ->all();

        // Sunday is gone; intervals are stored sorted by day and opening time.
        $this->assertSame([[2, '11:30', '15:00'], [2, '18:00', '23:00'], [6, '18:00', '02:00']], $saved);
    }

    public function test_empty_week_marks_every_day_closed(): void
    {
        $restaurant = $this->restaurant();
        RestaurantOpeningHour::create(['user_id' => $restaurant->id, 'weekday' => 3, 'opens_at' => '10:00', 'closes_at' => '14:00']);

        $this->saveHours($restaurant, [])->assertSessionHasNoErrors();

        $this->assertSame(0, RestaurantOpeningHour::count());
    }

    public function test_saving_hours_leaves_other_restaurants_untouched(): void
    {
        $restaurant = $this->restaurant();
        $other = $this->restaurant();
        RestaurantOpeningHour::create(['user_id' => $other->id, 'weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '15:00']);

        $this->saveHours($restaurant, [])->assertSessionHasNoErrors();

        $this->assertSame(1, RestaurantOpeningHour::where('user_id', $other->id)->count());
    }

    public function test_overlapping_intervals_on_the_same_day_are_rejected(): void
    {
        $restaurant = $this->restaurant();

        $this->saveHours($restaurant, [
            ['weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '15:00'],
            ['weekday' => 1, 'opens_at' => '14:00', 'closes_at' => '18:00'],
        ])->assertSessionHasErrors('hours.1.opens_at');

        $this->assertSame(0, RestaurantOpeningHour::count());
    }

    public function test_back_to_back_intervals_are_allowed(): void
    {
        $this->saveHours($this->restaurant(), [
            ['weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '15:00'],
            ['weekday' => 1, 'opens_at' => '15:00', 'closes_at' => '18:00'],
        ])->assertSessionHasNoErrors();
    }

    public function test_a_shift_past_midnight_cannot_overlap_the_next_morning(): void
    {
        $this->saveHours($this->restaurant(), [
            ['weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '03:00'],
            ['weekday' => 6, 'opens_at' => '02:00', 'closes_at' => '06:00'],
        ])->assertSessionHasErrors('hours.1.opens_at');
    }

    public function test_saturday_night_wraps_into_sunday(): void
    {
        $this->saveHours($this->restaurant(), [
            ['weekday' => 0, 'opens_at' => '01:00', 'closes_at' => '05:00'],
            ['weekday' => 6, 'opens_at' => '22:00', 'closes_at' => '02:00'],
        ])->assertSessionHasErrors('hours.1.opens_at');
    }

    public function test_hours_reject_invalid_entries(): void
    {
        $restaurant = $this->restaurant();

        $this->saveHours($restaurant, [['weekday' => 7, 'opens_at' => '10:00', 'closes_at' => '12:00']])
            ->assertSessionHasErrors('hours.0.weekday');
        $this->saveHours($restaurant, [['weekday' => 1, 'opens_at' => '25:00', 'closes_at' => '12:00']])
            ->assertSessionHasErrors('hours.0.opens_at');
        // Same opening and closing time is ambiguous (closed? 24h?), so it's refused.
        $this->saveHours($restaurant, [['weekday' => 1, 'opens_at' => '10:00', 'closes_at' => '10:00']])
            ->assertSessionHasErrors('hours.0.closes_at');
        $this->actingAs($restaurant)
            ->put(route('restaurant.establishment.hours.update'), [])
            ->assertSessionHasErrors('hours');
    }

    public function test_a_day_takes_at_most_three_intervals(): void
    {
        $this->saveHours($this->restaurant(), [
            ['weekday' => 1, 'opens_at' => '06:00', 'closes_at' => '08:00'],
            ['weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '14:00'],
            ['weekday' => 1, 'opens_at' => '17:00', 'closes_at' => '19:00'],
            ['weekday' => 1, 'opens_at' => '20:00', 'closes_at' => '22:00'],
        ])->assertSessionHasErrors('hours.3.opens_at');

        $this->assertSame(0, RestaurantOpeningHour::count());
    }

    // ── logo ─────────────────────────────────────────────────────────────────

    public function test_logo_upload_replaces_the_previous_file(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)
            ->post(route('restaurant.establishment.logo.update'), ['logo' => $this->png('logo.png')])
            ->assertSessionHasNoErrors();

        $first = $restaurant->fresh()->logo;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($restaurant)
            ->post(route('restaurant.establishment.logo.update'), ['logo' => $this->png('logo2.png')])
            ->assertSessionHasNoErrors();

        $second = $restaurant->fresh()->logo;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_logo_rejects_svg_and_non_images(): void
    {
        $restaurant = $this->restaurant();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($restaurant)
            ->post(route('restaurant.establishment.logo.update'), ['logo' => $svg])
            ->assertSessionHasErrors('logo');
        $this->actingAs($restaurant)
            ->post(route('restaurant.establishment.logo.update'), ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('logo');

        $this->assertNull($restaurant->fresh()->logo);
    }

    public function test_logo_can_be_removed(): void
    {
        $restaurant = $this->restaurant();
        Storage::disk('public')->put('logos/old.webp', 'x');
        $restaurant->update(['logo' => 'logos/old.webp']);

        $this->actingAs($restaurant)->delete(route('restaurant.establishment.logo.destroy'))->assertRedirect();

        $this->assertNull($restaurant->fresh()->logo);
        Storage::disk('public')->assertMissing('logos/old.webp');
    }

    // ── photos ───────────────────────────────────────────────────────────────

    public function test_photos_are_appended_after_the_existing_ones(): void
    {
        $restaurant = $this->restaurant();
        $existing = $this->photo($restaurant, 0);

        $this->actingAs($restaurant)
            ->post(route('restaurant.establishment.photos.store'), ['photos' => [
                $this->png('a.jpg'),
                $this->png('b.jpg'),
            ]])
            ->assertSessionHasNoErrors();

        $photos = RestaurantPhoto::where('user_id', $restaurant->id)->orderBy('position')->get();
        $this->assertSame([$existing->id], [$photos[0]->id]);
        $this->assertSame([0, 1, 2], $photos->pluck('position')->all());
        $photos->each(fn (RestaurantPhoto $p) => Storage::disk('public')->assertExists($p->path));
    }

    public function test_photo_limit_counts_the_photos_already_stored(): void
    {
        $restaurant = $this->restaurant();

        for ($i = 0; $i < RestaurantPhoto::MAX_PER_RESTAURANT - 1; $i++) {
            $this->photo($restaurant, $i);
        }

        $filesBefore = count(Storage::disk('public')->allFiles('venue'));

        $this->actingAs($restaurant)
            ->post(route('restaurant.establishment.photos.store'), ['photos' => [
                $this->png('a.jpg'),
                $this->png('b.jpg'),
            ]])
            ->assertSessionHasErrors('photos');

        $this->assertSame(RestaurantPhoto::MAX_PER_RESTAURANT - 1, RestaurantPhoto::count());
        // The rejected uploads must not stay on disk.
        $this->assertCount($filesBefore, Storage::disk('public')->allFiles('venue'));
    }

    public function test_photos_reject_non_images(): void
    {
        $this->actingAs($this->restaurant())
            ->post(route('restaurant.establishment.photos.store'), ['photos' => [
                UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf'),
            ]])
            ->assertSessionHasErrors('photos.0');

        $this->assertSame(0, RestaurantPhoto::count());
    }

    public function test_photos_can_be_reordered(): void
    {
        $restaurant = $this->restaurant();
        $a = $this->photo($restaurant, 0);
        $b = $this->photo($restaurant, 1);
        $c = $this->photo($restaurant, 2);

        $this->actingAs($restaurant)
            ->put(route('restaurant.establishment.photos.reorder'), ['ids' => [$c->id, $a->id, $b->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [$c->id, $a->id, $b->id],
            RestaurantPhoto::orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_reorder_rejects_foreign_or_partial_lists(): void
    {
        $restaurant = $this->restaurant();
        $a = $this->photo($restaurant, 0);
        $b = $this->photo($restaurant, 1);
        $foreign = $this->photo($this->restaurant(), 0);

        $this->actingAs($restaurant)
            ->put(route('restaurant.establishment.photos.reorder'), ['ids' => [$foreign->id, $a->id]])
            ->assertSessionHasErrors('ids.0');
        $this->actingAs($restaurant)
            ->put(route('restaurant.establishment.photos.reorder'), ['ids' => [$b->id]])
            ->assertSessionHasErrors('ids');
        $this->actingAs($restaurant)
            ->put(route('restaurant.establishment.photos.reorder'), ['ids' => [$a->id, $a->id]])
            ->assertSessionHasErrors('ids.0');

        $this->assertSame(0, $foreign->fresh()->position);
        $this->assertSame([$a->id, $b->id], RestaurantPhoto::where('user_id', $restaurant->id)->orderBy('position')->pluck('id')->all());
    }

    public function test_photo_delete_removes_the_file(): void
    {
        $restaurant = $this->restaurant();
        $photo = $this->photo($restaurant);

        $this->actingAs($restaurant)
            ->delete(route('restaurant.establishment.photos.destroy', $photo))
            ->assertRedirect();

        $this->assertModelMissing($photo);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_cannot_delete_another_restaurants_photo(): void
    {
        $photo = $this->photo($this->restaurant());

        $this->actingAs($this->restaurant())
            ->delete(route('restaurant.establishment.photos.destroy', $photo))
            ->assertForbidden();

        $this->assertModelExists($photo);
        Storage::disk('public')->assertExists($photo->path);
    }
}
