<?php

namespace Tests\Feature\Restaurant;

use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\RestaurantPost;
use App\Models\RestaurantReview;
use App\Models\RestaurantStory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** The list of restaurants to browse when the feed's search box is focused (/feed?explorar=1). */
class DiscoverRestaurantsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<int>  $ratings */
    private function reviews(User $restaurant, array $ratings): void
    {
        foreach ($ratings as $rating) {
            RestaurantReview::create(['restaurant_id' => $restaurant->id, 'user_id' => $this->customer()->id, 'rating' => $rating]);
        }
    }

    private function liveStory(User $restaurant, array $attributes = []): void
    {
        RestaurantStory::create([
            'user_id' => $restaurant->id,
            'media_type' => RestaurantStory::IMAGE,
            'status' => RestaurantStory::READY,
            'path' => 'stories/x.webp',
            'expires_at' => now()->addHours(5),
            ...$attributes,
        ]);
    }

    /**
     * Ids of the restaurants on a page of the list.
     *
     * @return list<int>
     */
    private function discoverIds(?User $viewer = null, int $page = 1): array
    {
        $ids = [];

        $this->actingAs($viewer ?? $this->customer())
            ->get(route('social.feed', ['explorar' => 1, 'pagina' => $page]))
            ->assertOk()
            ->assertInertia(function (Assert $p) use (&$ids) {
                $ids = array_column($p->toArray()['props']['discover']['data'], 'id');
            });

        return $ids;
    }

    // ── the mode ─────────────────────────────────────────────────────────────

    public function test_exploring_lists_restaurants_instead_of_posts(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        RestaurantPost::create(['user_id' => $restaurant->id, 'body' => 'Promo']);

        $this->actingAs($this->customer())->get(route('social.feed', ['explorar' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('social/feed')
                ->where('exploring', true)
                ->has('discover.data', 1)
                ->missing('posts')
                ->missing('stories'));

        $this->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('exploring', false)
                ->missing('discover')
                ->has('posts.data', 1)
                ->has('stories'));
    }

    public function test_focusing_the_search_loads_only_the_list(): void
    {
        $this->restaurant();

        // What the search box asks for when focused: a partial reload of the list.
        $this->actingAs($this->customer())->get(route('social.feed', ['explorar' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly(['exploring', 'discover'], fn (Assert $reload) => $reload
                    ->where('exploring', true)
                    ->has('discover.data', 1)
                    ->missing('posts')
                    ->missing('searchResults')));
    }

    public function test_typing_while_exploring_reloads_only_the_search(): void
    {
        $this->restaurant(['name' => 'Bar do Zé']);

        $this->actingAs($this->customer())->get(route('social.feed', ['explorar' => 1, 'q' => 'bar']))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly(['q', 'searchResults', 'exploring'], fn (Assert $reload) => $reload
                    ->missing('discover')
                    ->has('searchResults', 1)));
    }

    // ── what is listed ───────────────────────────────────────────────────────

    public function test_lists_public_restaurants_with_public_fields_only(): void
    {
        $restaurant = $this->restaurant([
            'name' => 'Bar do Zé',
            'phone' => '11999990000',
            'logo' => 'logos/l.webp',
            'banner' => 'banners/b.jpg',
            'address' => 'Rua das Flores, 123',
        ]);
        $this->restaurant(['profile_public' => false]);
        $this->waiterOf($restaurant);

        $this->actingAs($this->customer())->get(route('social.feed', ['explorar' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('discover.data', 1)
                ->where('discover.data.0', [
                    'id' => $restaurant->id,
                    'name' => 'Bar do Zé',
                    'handle' => $restaurant->handle,
                    'logo_url' => $restaurant->logo_url,
                    'rating' => ['average' => 0, 'count' => 0],
                    'banner_url' => $restaurant->banner_url,
                    'address' => 'Rua das Flores, 123',
                    'is_open' => null,
                    'has_stories' => false,
                ]))
            ->assertDontSee($restaurant->email, false)
            ->assertDontSee('11999990000', false);
    }

    public function test_shows_whether_it_is_open_now_in_the_business_timezone(): void
    {
        $open = $this->restaurant(['name' => 'A']);
        $closed = $this->restaurant(['name' => 'B']);
        RestaurantOpeningHour::create(['user_id' => $open->id, 'weekday' => 1, 'opens_at' => '18:00', 'closes_at' => '02:00']);
        RestaurantOpeningHour::create(['user_id' => $closed->id, 'weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '15:00']);
        // Monday 23:30 in São Paulo is Tuesday 02:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-16 23:30', config('app.business_timezone')));

        $this->actingAs($this->customer())->get(route('social.feed', ['explorar' => 1]))
            ->assertInertia(function (Assert $page) use ($open, $closed) {
                $byId = collect($page->toArray()['props']['discover']['data'])->keyBy('id');
                $this->assertTrue($byId[$open->id]['is_open']);
                $this->assertFalse($byId[$closed->id]['is_open']);
            });
    }

    public function test_marks_restaurants_with_a_story_on_air(): void
    {
        $withStory = $this->restaurant();
        $expired = $this->restaurant();
        $converting = $this->restaurant();
        $this->liveStory($withStory);
        $this->liveStory($expired, ['expires_at' => now()->subMinute()]);
        $this->liveStory($converting, ['status' => RestaurantStory::PROCESSING, 'path' => null]);

        $this->actingAs($this->customer())->get(route('social.feed', ['explorar' => 1]))
            ->assertInertia(function (Assert $page) use ($withStory) {
                $flags = collect($page->toArray()['props']['discover']['data'])->pluck('has_stories', 'id');
                $this->assertSame([$withStory->id], $flags->filter()->keys()->all());
            });
    }

    // ── order ────────────────────────────────────────────────────────────────

    public function test_well_rated_and_active_restaurants_come_first(): void
    {
        $plain = $this->restaurant(['name' => 'Sem nada']);
        $complete = $this->restaurant(['name' => 'Perfil completo', 'logo' => 'l.webp', 'banner' => 'b.jpg', 'address' => 'Rua A, 1']);
        RestaurantPhoto::create(['user_id' => $complete->id, 'path' => 'venue/1.jpg', 'position' => 0]);
        RestaurantOpeningHour::create(['user_id' => $complete->id, 'weekday' => 1, 'opens_at' => '18:00', 'closes_at' => '23:00']);
        $loved = $this->restaurant(['name' => 'Muito bem avaliado']);
        $this->reviews($loved, array_fill(0, 20, 5));
        $onAir = $this->restaurant(['name' => 'Com Hoje tem!']);
        $this->liveStory($onAir);
        $this->reviews($onAir, [5, 5, 4]);

        $this->assertSame([$onAir->id, $loved->id, $complete->id, $plain->id], $this->discoverIds());
    }

    public function test_a_single_perfect_review_does_not_beat_many_good_ones(): void
    {
        $lucky = $this->restaurant(['name' => 'Uma avaliação']);
        $this->reviews($lucky, [5]);
        $proven = $this->restaurant(['name' => 'Muitas avaliações']);
        $this->reviews($proven, array_merge(array_fill(0, 15, 5), array_fill(0, 5, 4)));
        $bad = $this->restaurant(['name' => 'Mal avaliado']);
        $this->reviews($bad, array_fill(0, 10, 1));
        $new = $this->restaurant(['name' => 'Novo']);

        $this->assertSame([$proven->id, $lucky->id, $new->id, $bad->id], $this->discoverIds());
    }

    public function test_a_recent_post_counts_an_old_one_does_not(): void
    {
        $recent = $this->restaurant();
        $old = $this->restaurant();
        RestaurantPost::create(['user_id' => $recent->id, 'body' => 'Hoje!']);
        $oldPost = RestaurantPost::create(['user_id' => $old->id, 'body' => 'Mês passado']);
        RestaurantPost::whereKey($oldPost->id)->update(['created_at' => now()->subDays(30)]);
        // Many restaurants without posts, so a coincidence of the daily shuffle can't explain the order.
        foreach (range(1, 5) as $i) {
            $this->restaurant();
        }

        $ids = $this->discoverIds();
        $this->assertSame($recent->id, $ids[0]);
        $this->assertNotSame($old->id, $ids[0]);
    }

    public function test_ties_are_shuffled_daily_but_stable_within_the_day(): void
    {
        foreach (range(1, 10) as $i) {
            $this->restaurant();
        }

        $customer = $this->customer();
        $this->travelTo(Carbon::parse('2026-11-16 10:00', config('app.business_timezone')));
        $morning = $this->discoverIds($customer);
        $this->travelTo(Carbon::parse('2026-11-16 22:00', config('app.business_timezone')));
        $evening = $this->discoverIds($customer);
        $this->travelTo(Carbon::parse('2026-11-17 10:00', config('app.business_timezone')));
        $nextDay = $this->discoverIds($customer);

        $this->assertSame($morning, $evening);
        $this->assertNotSame($morning, $nextDay);
        $this->assertEqualsCanonicalizing($morning, $nextDay);
    }

    // ── pages ────────────────────────────────────────────────────────────────

    public function test_pages_cover_every_restaurant_once(): void
    {
        $all = collect(range(1, 27))->map(fn () => $this->restaurant()->id)->all();
        $customer = $this->customer();

        $pages = [$this->discoverIds($customer, 1), $this->discoverIds($customer, 2), $this->discoverIds($customer, 3)];

        $this->assertCount(12, $pages[0]);
        $this->assertCount(12, $pages[1]);
        $this->assertCount(3, $pages[2]);
        $this->assertEqualsCanonicalizing($all, array_merge(...$pages));
        $this->assertSame([], $this->discoverIds($customer, 4));
    }

    public function test_the_list_tells_the_page_how_to_load_more(): void
    {
        foreach (range(1, 13) as $i) {
            $this->restaurant();
        }

        // InfiniteScroll reads the page-level scrollProps to ask for the next page.
        $first = $this->actingAs($this->customer())->get(route('social.feed', ['explorar' => 1]))->viewData('page');
        $this->assertSame('pagina', $first['scrollProps']['discover']['pageName']);
        $this->assertSame(2, $first['scrollProps']['discover']['nextPage']);

        $last = $this->get(route('social.feed', ['explorar' => 1, 'pagina' => 2]))->viewData('page');
        $this->assertCount(1, $last['props']['discover']['data']);
        $this->assertNull($last['scrollProps']['discover']['nextPage']);
    }

    public function test_queries_do_not_grow_with_the_page(): void
    {
        $customer = $this->customer();
        $count = function () use ($customer): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->actingAs($customer)->get(route('social.feed', ['explorar' => 1]))->assertOk();

            return $queries;
        };

        $first = $this->restaurant();
        RestaurantOpeningHour::create(['user_id' => $first->id, 'weekday' => 1, 'opens_at' => '18:00', 'closes_at' => '23:00']);
        $one = $count();

        foreach (range(1, 11) as $i) {
            $restaurant = $this->restaurant();
            RestaurantOpeningHour::create(['user_id' => $restaurant->id, 'weekday' => 2, 'opens_at' => '18:00', 'closes_at' => '23:00']);
            $this->reviews($restaurant, [4]);
        }

        // reviews() creates customers; that doesn't touch the feed's queries.
        $this->assertSame($one, $count());
    }
}
