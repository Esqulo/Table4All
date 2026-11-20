<?php

namespace Tests\Feature\Restaurant;

use App\Jobs\GeocodeRestaurant;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\RestaurantPost;
use App\Models\RestaurantReview;
use App\Models\RestaurantStory;
use App\Models\RestaurantTable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** The discovery page (/descobrir): search, filters, sort orders and the grid of restaurants. */
class DiscoverRestaurantsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    /** Praça da Sé, São Paulo. */
    private const SE = ['lat' => -23.5505, 'lng' => -46.6333];

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

    private function hours(User $restaurant, int $weekday, string $opens, string $closes): void
    {
        RestaurantOpeningHour::create(['user_id' => $restaurant->id, 'weekday' => $weekday, 'opens_at' => $opens, 'closes_at' => $closes]);
    }

    /** @param  array<string, mixed>  $query */
    private function visit(array $query = [], ?User $viewer = null): TestResponse
    {
        return $this->actingAs($viewer ?? $this->customer())
            ->get(route('social.discover', $query))
            ->assertOk();
    }

    /**
     * The cards on a page of the grid.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function cards(array $query = [], ?User $viewer = null): array
    {
        return $this->visit($query, $viewer)->viewData('page')['props']['discover']['data'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function ids(array $query = [], ?User $viewer = null): array
    {
        return array_column($this->cards($query, $viewer), 'id');
    }

    /** @return list<string> names of the restaurants found for $q */
    private function search(string $q): array
    {
        return array_column($this->cards(['q' => $q]), 'name');
    }

    // ── the page ─────────────────────────────────────────────────────────────

    public function test_discovery_has_its_own_page_with_everything_off_by_default(): void
    {
        $this->restaurant();

        $this->assertSame(url('/descobrir'), route('social.discover'));
        $this->visit()->assertInertia(fn (Assert $page) => $page
            ->component('social/discover')
            ->has('discover.data', 1)
            ->where('filters', ['q' => '', 'crowd' => [1, 3], 'price' => [1, 3], 'open' => false, 'sort' => 'relevance'])
            ->where('origin', null));
    }

    public function test_the_feed_no_longer_searches_and_its_old_search_links_lead_here(): void
    {
        RestaurantPost::create(['user_id' => $this->restaurant(['name' => 'Bar do Zé'])->id, 'body' => 'Promo']);
        $customer = $this->customer();

        $this->actingAs($customer)->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('social/feed')
                ->has('posts.data', 1)
                ->missing('discover')
                ->missing('searchResults')
                ->missing('exploring'));

        $this->get('/feed?explorar=1')->assertRedirect(route('social.discover'));
        $this->get('/feed?explorar=1&q=bar')->assertRedirect(route('social.discover', ['q' => 'bar']));
        $this->get('/feed?q=bar')->assertRedirect(route('social.discover', ['q' => 'bar']));
        // The feed's former address keeps the term all the way here.
        $this->followingRedirects()->get('/vempro?q=bar')
            ->assertInertia(fn (Assert $page) => $page->component('social/discover')->where('filters.q', 'bar'));
    }

    public function test_requires_a_verified_account(): void
    {
        $this->get(route('social.discover'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('social.discover'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_changing_a_filter_reloads_only_the_grid(): void
    {
        $this->restaurant(['price_level' => 2]);

        $this->visit(['preco' => '2-3'])
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly(['discover', 'filters', 'origin'], fn (Assert $reload) => $reload
                    ->has('discover.data', 1)
                    ->where('filters.price', [2, 3])));
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
            'price_level' => 2,
            'table_capacity' => 10,
            'latitude' => self::SE['lat'],
            'longitude' => self::SE['lng'],
        ]);
        $this->restaurant(['profile_public' => false]);
        $this->waiterOf($restaurant);

        $this->visit()
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
                    'price_level' => 2,
                    'crowd_level' => 1,
                    // Only when sorting by distance.
                    'distance_km' => null,
                ]))
            ->assertDontSee($restaurant->email, false)
            ->assertDontSee('11999990000', false)
            // The exact position stays on the server.
            ->assertDontSee('-46.6333', false);
    }

    public function test_shows_whether_it_is_open_now_in_the_business_timezone(): void
    {
        $open = $this->restaurant(['name' => 'A']);
        $closed = $this->restaurant(['name' => 'B']);
        $this->hours($open, 1, '18:00', '02:00');
        $this->hours($closed, 1, '11:00', '15:00');
        // Monday 23:30 in São Paulo is Tuesday 02:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-16 23:30', config('app.business_timezone')));

        $byId = collect($this->cards())->keyBy('id');
        $this->assertTrue($byId[$open->id]['is_open']);
        $this->assertFalse($byId[$closed->id]['is_open']);
    }

    public function test_marks_restaurants_with_a_story_on_air(): void
    {
        $withStory = $this->restaurant();
        $expired = $this->restaurant();
        $converting = $this->restaurant();
        $this->liveStory($withStory);
        $this->liveStory($expired, ['expires_at' => now()->subMinute()]);
        $this->liveStory($converting, ['status' => RestaurantStory::PROCESSING, 'path' => null]);

        $flags = collect($this->cards())->pluck('has_stories', 'id');
        $this->assertSame([$withStory->id], $flags->filter()->keys()->all());
    }

    // ── default order ────────────────────────────────────────────────────────

    public function test_well_rated_and_active_restaurants_come_first(): void
    {
        $plain = $this->restaurant(['name' => 'Sem nada']);
        $complete = $this->restaurant(['name' => 'Perfil completo', 'logo' => 'l.webp', 'banner' => 'b.jpg', 'address' => 'Rua A, 1']);
        RestaurantPhoto::create(['user_id' => $complete->id, 'path' => 'venue/1.jpg', 'position' => 0]);
        $this->hours($complete, 1, '18:00', '23:00');
        $loved = $this->restaurant(['name' => 'Muito bem avaliado']);
        $this->reviews($loved, array_fill(0, 20, 5));
        $onAir = $this->restaurant(['name' => 'Com Hoje tem!']);
        $this->liveStory($onAir);
        $this->reviews($onAir, [5, 5, 4]);

        $this->assertSame([$onAir->id, $loved->id, $complete->id, $plain->id], $this->ids());
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

        $this->assertSame([$proven->id, $lucky->id, $new->id, $bad->id], $this->ids());
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

        $ids = $this->ids();
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
        $morning = $this->ids([], $customer);
        $this->travelTo(Carbon::parse('2026-11-16 22:00', config('app.business_timezone')));
        $evening = $this->ids([], $customer);
        $this->travelTo(Carbon::parse('2026-11-17 10:00', config('app.business_timezone')));
        $nextDay = $this->ids([], $customer);

        $this->assertSame($morning, $evening);
        $this->assertNotSame($morning, $nextDay);
        $this->assertEqualsCanonicalizing($morning, $nextDay);
    }

    // ── pages ────────────────────────────────────────────────────────────────

    public function test_pages_cover_every_restaurant_once(): void
    {
        $all = collect(range(1, 50))->map(fn () => $this->restaurant()->id)->all();
        $customer = $this->customer();

        $pages = [
            $this->ids(['pagina' => 1], $customer),
            $this->ids(['pagina' => 2], $customer),
            $this->ids(['pagina' => 3], $customer),
        ];

        $this->assertCount(24, $pages[0]);
        $this->assertCount(24, $pages[1]);
        $this->assertCount(2, $pages[2]);
        $this->assertEqualsCanonicalizing($all, array_merge(...$pages));
        $this->assertSame([], $this->ids(['pagina' => 4], $customer));
    }

    public function test_pages_of_a_filtered_list_keep_the_filters(): void
    {
        $cheap = collect(range(1, 26))->map(fn () => $this->restaurant(['price_level' => 1])->id)->all();
        foreach (range(1, 5) as $i) {
            $this->restaurant(['price_level' => 3]);
        }
        $customer = $this->customer();

        $first = $this->ids(['preco' => '1-1', 'pagina' => 1], $customer);
        $second = $this->ids(['preco' => '1-1', 'pagina' => 2], $customer);

        $this->assertEqualsCanonicalizing($cheap, array_merge($first, $second));
    }

    public function test_the_grid_tells_the_page_how_to_load_more(): void
    {
        foreach (range(1, 25) as $i) {
            $this->restaurant();
        }

        // InfiniteScroll reads the page-level scrollProps to ask for the next page.
        $first = $this->visit()->viewData('page');
        $this->assertSame('pagina', $first['scrollProps']['discover']['pageName']);
        $this->assertSame(2, $first['scrollProps']['discover']['nextPage']);

        $last = $this->get(route('social.discover', ['pagina' => 2]))->viewData('page');
        $this->assertCount(1, $last['props']['discover']['data']);
        $this->assertNull($last['scrollProps']['discover']['nextPage']);
    }

    public function test_queries_do_not_grow_with_the_page(): void
    {
        $customer = $this->customer();
        $everything = ['q' => 'bar', 'lotacao' => '1-2', 'preco' => '1-2', 'aberto' => 1, 'ordem' => 'distancia', ...self::SE];
        $count = function (array $query) use ($customer): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->actingAs($customer)->get(route('social.discover', $query))->assertOk();

            return $queries;
        };

        $first = $this->restaurant(['name' => 'Bar 0', 'price_level' => 1, 'table_capacity' => 10]);
        $this->hours($first, Carbon::now(config('app.business_timezone'))->dayOfWeek, '00:00', '00:00');
        $plain = $count([]);
        $filtered = $count($everything);

        foreach (range(1, 11) as $i) {
            $restaurant = $this->restaurant(['name' => "Bar {$i}", 'price_level' => 1, 'table_capacity' => 10]);
            $this->hours($restaurant, Carbon::now(config('app.business_timezone'))->dayOfWeek, '00:00', '00:00');
            $this->openTable($restaurant);
            $this->reviews($restaurant, [4]);
        }

        // reviews() creates customers; that doesn't touch the page's queries.
        $this->assertSame($plain, $count([]));
        $this->assertSame($filtered, $count($everything));
        $this->assertCount(12, $this->cards($everything, $customer));
    }

    // ── search ───────────────────────────────────────────────────────────────

    public function test_search_finds_restaurants_by_name_or_handle(): void
    {
        $this->restaurant(['name' => 'Bar do Zé']);
        $this->restaurant(['name' => 'Pizzaria Bella']);
        $this->restaurant(['name' => 'Zé Lanches'])->update(['handle' => 'lanchonete']);

        $this->assertEqualsCanonicalizing(['Bar do Zé', 'Zé Lanches'], $this->search('zé'));
        $this->assertSame(['Pizzaria Bella'], $this->search('PIZZA'));
        $this->assertSame(['Zé Lanches'], $this->search('@lanchon'));
        // Typed without the accent and with spaces, it still finds the @handle.
        $this->assertSame(['Bar do Zé'], $this->search('bar do ze'));
    }

    public function test_search_ignores_accents_both_ways(): void
    {
        $this->restaurant(['name' => 'Grão & Prosa Café'])->update(['handle' => 'graoprosa']);
        $this->restaurant(['name' => 'Cafeteria Central']);

        // Typed without accents, as people do on a phone…
        $this->assertEqualsCanonicalizing(['Cafeteria Central', 'Grão & Prosa Café'], $this->search('cafe'));
        $this->assertSame(['Grão & Prosa Café'], $this->search('grao'));
        // …and with accents the name doesn't have.
        $this->assertSame(['Cafeteria Central'], $this->search('céntral'));
    }

    public function test_renaming_a_restaurant_updates_what_the_search_finds(): void
    {
        $restaurant = $this->restaurant(['name' => 'Boteco Antigo']);
        $restaurant->update(['name' => 'Açaí da Praia']);

        $this->assertSame('acai da praia', $restaurant->fresh()->search_name);
        $this->assertSame(['Açaí da Praia'], $this->search('acai'));
        $this->assertSame(['Açaí da Praia'], $this->search('praia'));
        // Renaming keeps the @handle (links keep working), so the old word still finds it there.
        $this->assertSame('boteco-antigo', $restaurant->fresh()->handle);
        $this->assertSame(['Açaí da Praia'], $this->search('antigo'));
    }

    public function test_exact_handle_comes_first(): void
    {
        $this->restaurant(['name' => 'A Casa do Bar']);
        $this->reviews($this->restaurant(['name' => 'O Melhor Bar']), array_fill(0, 20, 5));
        $this->restaurant(['name' => 'Bar']);

        $this->assertSame(['Bar', 'O Melhor Bar', 'A Casa do Bar'], $this->search('bar'));
    }

    public function test_search_only_lists_public_restaurants(): void
    {
        $this->restaurant(['name' => 'Bar Visível', 'phone' => '11999990000']);
        $this->restaurant(['name' => 'Bar Oculto', 'profile_public' => false]);
        $customer = $this->customer();
        $customer->update(['name' => 'Bar Cliente']);

        $this->visit(['q' => 'bar'], $customer)
            ->assertDontSee('11999990000', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.q', 'bar')
                ->has('discover.data', 1)
                ->where('discover.data.0.name', 'Bar Visível'));
    }

    public function test_short_or_empty_terms_do_not_filter_the_list(): void
    {
        $this->restaurant(['name' => 'Bar']);
        $this->restaurant(['name' => 'Pizzaria']);

        foreach (['', ' ', 'b', '@', '%%'] as $q) {
            $this->assertCount(2, $this->cards(['q' => $q]), "q = '{$q}'");
        }
    }

    public function test_symbols_in_the_term_separate_words_and_are_not_wildcards(): void
    {
        $this->restaurant(['name' => 'Bar 50% off']);
        $this->restaurant(['name' => 'Pizzaria Bella']);

        // "%" and "_" are neither LIKE wildcards nor tsquery syntax: just separators.
        $this->assertSame(['Bar 50% off'], $this->search('50%'));
        $this->assertSame([], $this->search('b_r'));
        $this->assertSame([], $this->search("pizza' | bar"));
        $this->assertSame(['Pizzaria Bella'], $this->search('pizza & !bella:*'));
    }

    public function test_search_matches_the_start_of_every_word_in_any_order(): void
    {
        $this->restaurant(['name' => 'Grão & Prosa Café'])->update(['handle' => 'graoprosa']);
        $this->restaurant(['name' => 'Pizzaria Forno a Lenha'])->update(['handle' => 'forno-a-lenha']);
        $this->restaurant(['name' => 'Boteco do Seu Zé']);

        $this->assertSame(['Grão & Prosa Café'], $this->search('grao prosa'));
        $this->assertSame(['Grão & Prosa Café'], $this->search('prosa grão'));
        $this->assertSame(['Pizzaria Forno a Lenha'], $this->search('piz for'));
        $this->assertSame(['Boteco do Seu Zé'], $this->search('boteco ze'));
        // Every word must match: one that matches nothing rules the restaurant out.
        $this->assertSame([], $this->search('boteco pizza'));
        // The start of a word, not the middle.
        $this->assertSame([], $this->search('izza'));
    }

    public function test_search_follows_handle_changes(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $restaurant->update(['handle' => 'esquina-ze']);

        $this->assertSame(['Bar do Zé'], $this->search('esquina'));
        $this->assertSame(['Bar do Zé'], $this->search('@esquina-ze'));
    }

    // ── how full ("lotação") ─────────────────────────────────────────────────

    /** A restaurant with $capacity tables, $open of them open now. */
    private function venue(?int $capacity, int $open, string $name = 'Bar'): User
    {
        $restaurant = $this->restaurant(['name' => $name, 'table_capacity' => $capacity]);

        for ($i = 0; $i < $open; $i++) {
            $this->openTable($restaurant);
        }

        return $restaurant;
    }

    public function test_crowd_level_is_the_share_of_tables_open_in_thirds(): void
    {
        $empty = $this->venue(9, 0);
        $calm = $this->venue(9, 2);
        $middle = $this->venue(9, 3);
        $almost = $this->venue(9, 5);
        $packed = $this->venue(9, 6);
        $overbooked = $this->venue(9, 12);
        $unknown = $this->venue(null, 4);

        $levels = collect($this->cards())->pluck('crowd_level', 'id');

        $this->assertSame(1, $levels[$empty->id]);
        $this->assertSame(1, $levels[$calm->id]);
        $this->assertSame(2, $levels[$middle->id]);
        $this->assertSame(2, $levels[$almost->id]);
        $this->assertSame(3, $levels[$packed->id]);
        $this->assertSame(3, $levels[$overbooked->id]);
        $this->assertNull($levels[$unknown->id]);
    }

    public function test_only_tables_open_now_count_towards_the_crowd(): void
    {
        $restaurant = $this->venue(3, 0);
        $this->closedTable($restaurant);
        $this->openTable($restaurant)->delete(); // soft-deleted
        // Left open since yesterday: forgotten, not people at the table.
        $forgotten = $this->openTable($restaurant);
        RestaurantTable::whereKey($forgotten->id)->update(['created_at' => now()->subHours(13)]);
        // Another restaurant's tables.
        $this->venue(3, 3);

        $this->assertSame(1, collect($this->cards())->firstWhere('id', $restaurant->id)['crowd_level']);

        $this->openTable($restaurant);
        $this->openTable($restaurant);
        $this->assertSame(3, collect($this->cards())->firstWhere('id', $restaurant->id)['crowd_level']);
    }

    public function test_filters_by_how_full_and_leaves_out_unknown_ones(): void
    {
        $calm = $this->venue(9, 1);
        $middle = $this->venue(9, 4);
        $packed = $this->venue(9, 8);
        $unknown = $this->venue(null, 0);

        $this->assertEqualsCanonicalizing([$calm->id], $this->ids(['lotacao' => '1-1']));
        $this->assertEqualsCanonicalizing([$packed->id], $this->ids(['lotacao' => '3-3']));
        $this->assertEqualsCanonicalizing([$middle->id, $packed->id], $this->ids(['lotacao' => '2-3']));
        $this->assertEqualsCanonicalizing([$calm->id, $middle->id], $this->ids(['lotacao' => '1-2']));
        // The whole range is no filter at all.
        $this->assertEqualsCanonicalizing([$calm->id, $middle->id, $packed->id, $unknown->id], $this->ids(['lotacao' => '1-3']));
    }

    // ── price and open now ───────────────────────────────────────────────────

    public function test_filters_by_price_level(): void
    {
        $cheap = $this->restaurant(['price_level' => 1]);
        $mid = $this->restaurant(['price_level' => 2]);
        $fancy = $this->restaurant(['price_level' => 3]);
        $unset = $this->restaurant();

        $this->assertEqualsCanonicalizing([$cheap->id], $this->ids(['preco' => '1-1']));
        $this->assertEqualsCanonicalizing([$cheap->id, $mid->id], $this->ids(['preco' => '1-2']));
        $this->assertEqualsCanonicalizing([$mid->id, $fancy->id], $this->ids(['preco' => '2-3']));
        $this->assertEqualsCanonicalizing([$cheap->id, $mid->id, $fancy->id, $unset->id], $this->ids(['preco' => '1-3']));
    }

    public function test_filters_restaurants_open_now_overnight_shifts_included(): void
    {
        $dinner = $this->restaurant(['name' => 'Jantar']);
        $this->hours($dinner, 1, '18:00', '23:00');
        $lateNight = $this->restaurant(['name' => 'Madrugada']);
        $this->hours($lateNight, 1, '20:00', '03:00'); // Monday night into Tuesday
        $lunch = $this->restaurant(['name' => 'Almoço']);
        $this->hours($lunch, 1, '11:00', '15:00');
        $this->restaurant(['name' => 'Sem horários']);

        // Monday 22:00 in São Paulo (Tuesday 01:00 UTC).
        Carbon::setTestNow(Carbon::parse('2026-11-16 22:00', config('app.business_timezone')));
        $this->assertEqualsCanonicalizing([$dinner->id, $lateNight->id], $this->ids(['aberto' => 1]));

        // Tuesday 01:30: only yesterday's overnight shift is still running.
        Carbon::setTestNow(Carbon::parse('2026-11-17 01:30', config('app.business_timezone')));
        $this->assertSame([$lateNight->id], $this->ids(['aberto' => 1]));

        // Tuesday 03:00: it just closed.
        Carbon::setTestNow(Carbon::parse('2026-11-17 03:00', config('app.business_timezone')));
        $this->assertSame([], $this->ids(['aberto' => 1]));
    }

    public function test_search_and_filters_combine(): void
    {
        $match = $this->restaurant(['name' => 'Bar Barato', 'price_level' => 1]);
        $this->restaurant(['name' => 'Bar Caro', 'price_level' => 3]);
        $this->restaurant(['name' => 'Pizzaria Econômica', 'price_level' => 1]);

        $this->visit(['q' => 'bar', 'preco' => '1-1'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['q' => 'bar', 'crowd' => [1, 3], 'price' => [1, 1], 'open' => false, 'sort' => 'relevance'])
                ->has('discover.data', 1)
                ->where('discover.data.0.id', $match->id));
    }

    public function test_malformed_parameters_are_ignored(): void
    {
        $this->restaurant(['price_level' => 1]);
        $this->restaurant();

        foreach ([
            ['lotacao' => '0-9'],
            ['lotacao' => '1'],
            ['preco' => 'abc'],
            ['preco' => ['1', '2']],
            ['ordem' => 'preco'],
            ['ordem' => 'distancia', 'lat' => '999', 'lng' => 'x'],
            ['q' => ['x']],
        ] as $query) {
            $this->assertCount(2, $this->cards($query), json_encode($query));
        }

        // A reversed range is the same range.
        $this->visit(['preco' => '2-1'])->assertInertia(fn (Assert $page) => $page->where('filters.price', [1, 2]));
    }

    // ── sort orders ──────────────────────────────────────────────────────────

    public function test_sorts_by_rating_without_letting_one_review_win(): void
    {
        $lucky = $this->restaurant(['name' => 'Uma avaliação 5']);
        $this->reviews($lucky, [5]);
        $proven = $this->restaurant(['name' => 'Muitas avaliações']);
        $this->reviews($proven, array_fill(0, 12, 5));
        $good = $this->restaurant(['name' => 'Bom']);
        $this->reviews($good, array_fill(0, 12, 4));
        $bad = $this->restaurant(['name' => 'Ruim']);
        $this->reviews($bad, array_fill(0, 6, 2));
        // A story on air would lead the default order, but not this one.
        $new = $this->restaurant(['name' => 'Sem avaliações']);
        $this->liveStory($new);

        $this->visit(['ordem' => 'avaliacao'])->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'rating'));
        $this->assertSame([$proven->id, $good->id, $lucky->id, $new->id, $bad->id], $this->ids(['ordem' => 'avaliacao']));
    }

    public function test_sorts_by_distance_from_the_device(): void
    {
        $paulista = $this->restaurant(['name' => 'Paulista', 'latitude' => -23.5614, 'longitude' => -46.6559]);
        $rio = $this->restaurant(['name' => 'Rio', 'latitude' => -22.9068, 'longitude' => -43.1729]);
        $here = $this->restaurant(['name' => 'Na Sé', 'latitude' => -23.5507, 'longitude' => -46.6334]);
        $nowhere = $this->restaurant(['name' => 'Sem endereço no mapa']);
        $this->reviews($nowhere, array_fill(0, 20, 5));

        $response = $this->visit(['ordem' => 'distancia', ...self::SE]);
        $response->assertInertia(fn (Assert $page) => $page->where('origin', 'device')->where('filters.sort', 'distance'));

        $cards = $response->viewData('page')['props']['discover']['data'];
        // Closest first; restaurants not found on the map go last.
        $this->assertSame([$here->id, $paulista->id, $rio->id, $nowhere->id], array_column($cards, 'id'));
        // The viewer's position is rounded to ~100 m.
        $this->assertLessThan(0.2, $cards[0]['distance_km']);
        $this->assertEqualsWithDelta(2.5, $cards[1]['distance_km'], 0.3);
        $this->assertEqualsWithDelta(360, $cards[2]['distance_km'], 10);
        $this->assertNull($cards[3]['distance_km']);
    }

    public function test_without_the_device_position_the_ip_places_the_viewer(): void
    {
        config(['services.ip_location.url' => 'https://ip.example/{ip}']);
        Http::fake(['ip.example/*' => Http::response(['success' => true, 'latitude' => -22.9, 'longitude' => -43.2])]);
        $sp = $this->restaurant(['name' => 'SP', 'latitude' => self::SE['lat'], 'longitude' => self::SE['lng']]);
        $rio = $this->restaurant(['name' => 'Rio', 'latitude' => -22.9068, 'longitude' => -43.1729]);
        $customer = $this->customer();

        foreach (range(1, 2) as $i) {
            $response = $this->actingAs($customer)
                ->withServerVariables(['REMOTE_ADDR' => '200.147.3.10'])
                ->get(route('social.discover', ['ordem' => 'distancia']));
            $response->assertInertia(fn (Assert $page) => $page->where('origin', 'ip'));
            $this->assertSame([$rio->id, $sp->id], array_column($response->viewData('page')['props']['discover']['data'], 'id'));
        }

        // Asked once: the answer is cached for the address.
        $this->assertCount(1, Http::recorded(fn ($request) => str_starts_with($request->url(), 'https://ip.example/')));
        Http::assertSent(fn ($request) => $request->url() === 'https://ip.example/200.147.3.10');
    }

    public function test_cloudflare_location_headers_come_before_asking_a_service(): void
    {
        config(['services.ip_location.url' => 'https://ip.example/{ip}']);
        Http::fake();
        $sp = $this->restaurant(['latitude' => self::SE['lat'], 'longitude' => self::SE['lng']]);
        $rio = $this->restaurant(['latitude' => -22.9068, 'longitude' => -43.1729]);

        $response = $this->actingAs($this->customer())
            ->withServerVariables(['REMOTE_ADDR' => '200.147.3.10'])
            ->withHeaders(['CF-IPLatitude' => '-22.91', 'CF-IPLongitude' => '-43.17'])
            ->get(route('social.discover', ['ordem' => 'distancia']));

        $response->assertInertia(fn (Assert $page) => $page->where('origin', 'ip'));
        $this->assertSame([$rio->id, $sp->id], array_column($response->viewData('page')['props']['discover']['data'], 'id'));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://ip.example/'));
    }

    public function test_when_the_viewer_cannot_be_placed_the_default_order_stays(): void
    {
        config(['services.ip_location.url' => 'https://ip.example/{ip}']);
        Http::fake(['ip.example/*' => Http::response(['success' => false], 200)]);
        $far = $this->restaurant(['latitude' => -22.9068, 'longitude' => -43.1729]);
        $loved = $this->restaurant(['latitude' => -10.0, 'longitude' => -50.0]);
        $this->reviews($loved, array_fill(0, 20, 5));
        $customer = $this->customer();

        // A local address (the LAN, Docker) isn't looked up at all…
        $this->actingAs($customer)->withServerVariables(['REMOTE_ADDR' => '192.168.0.10'])
            ->get(route('social.discover', ['ordem' => 'distancia']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('origin', null)
                ->where('filters.sort', 'distance')
                ->where('discover.data.0.id', $loved->id)
                ->where('discover.data.0.distance_km', null));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://ip.example/'));

        // …and a service that doesn't know the address leaves it the same.
        $this->actingAs($customer)->withServerVariables(['REMOTE_ADDR' => '200.147.3.10'])
            ->get(route('social.discover', ['ordem' => 'distancia']))
            ->assertInertia(fn (Assert $page) => $page->where('origin', null)->where('discover.data.0.id', $loved->id));
        $this->assertNotNull($far);
    }

    public function test_the_device_position_is_used_only_when_sorting_by_distance(): void
    {
        $this->restaurant(['latitude' => self::SE['lat'], 'longitude' => self::SE['lng']]);

        $this->visit(self::SE)->assertInertia(fn (Assert $page) => $page
            ->where('origin', null)
            ->where('discover.data.0.distance_km', null));
    }

    // ── what the restaurant sets ─────────────────────────────────────────────

    public function test_restaurant_sets_price_level_and_table_count(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)
            ->from(route('restaurant.establishment.edit'))
            ->put(route('restaurant.establishment.profile.update'), [
                'handle' => $restaurant->handle,
                'profile_public' => true,
                'price_level' => 2,
                'table_capacity' => 18,
            ])
            ->assertSessionHasNoErrors();

        $restaurant->refresh();
        $this->assertSame(2, $restaurant->price_level);
        $this->assertSame(18, $restaurant->table_capacity);
        $this->get(route('restaurant.establishment.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('priceLevel', 2)
                ->where('tableCapacity', 18)
                ->where('addressLocated', false));

        // The "make public" shortcut sends only handle and visibility: the rest stays.
        $this->put(route('restaurant.establishment.profile.update'), ['handle' => $restaurant->handle, 'profile_public' => false])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $restaurant->fresh()->price_level);
        $this->assertSame(18, $restaurant->fresh()->table_capacity);

        // And both can be cleared.
        $this->put(route('restaurant.establishment.profile.update'), [
            'handle' => $restaurant->handle,
            'profile_public' => true,
            'price_level' => null,
            'table_capacity' => null,
        ])->assertSessionHasNoErrors();
        $this->assertNull($restaurant->fresh()->price_level);
        $this->assertNull($restaurant->fresh()->table_capacity);
    }

    public function test_price_level_and_table_count_are_validated(): void
    {
        $restaurant = $this->restaurant(['price_level' => 1, 'table_capacity' => 5]);

        foreach ([
            ['price_level' => 0],
            ['price_level' => 4],
            ['price_level' => 'caro'],
            ['table_capacity' => 0],
            ['table_capacity' => 501],
            ['table_capacity' => 2.5],
        ] as $data) {
            $this->actingAs($restaurant)
                ->from(route('restaurant.establishment.edit'))
                ->put(route('restaurant.establishment.profile.update'), [
                    'handle' => $restaurant->handle,
                    'profile_public' => true,
                    ...$data,
                ])
                ->assertSessionHasErrors(array_keys($data));
        }

        $this->assertSame(1, $restaurant->fresh()->price_level);
        $this->assertSame(5, $restaurant->fresh()->table_capacity);
    }

    public function test_a_new_address_is_found_on_the_map(): void
    {
        config(['services.geocoding.url' => 'https://geo.example/search']);
        Http::fake(['geo.example/*' => Http::response([['lat' => '-23.5614', 'lon' => '-46.6559']])]);
        $restaurant = $this->restaurant(['latitude' => -1.0, 'longitude' => -1.0]);

        $this->actingAs($restaurant)
            ->put(route('restaurant.establishment.profile.update'), [
                'handle' => $restaurant->handle,
                'profile_public' => true,
                'address' => 'Av. Paulista, 1000 - São Paulo',
            ])
            ->assertSessionHasNoErrors();

        $restaurant->refresh();
        $this->assertSame(-23.5614, $restaurant->latitude);
        $this->assertSame(-46.6559, $restaurant->longitude);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://geo.example/search')
            && $request['q'] === 'Av. Paulista, 1000 - São Paulo'
            && $request['countrycodes'] === 'br'
            && $request->hasHeader('User-Agent'));
        $this->get(route('restaurant.establishment.edit'))->assertInertia(fn (Assert $page) => $page->where('addressLocated', true));
    }

    public function test_an_address_not_found_or_removed_leaves_no_position(): void
    {
        config(['services.geocoding.url' => 'https://geo.example/search']);
        Http::fake(['geo.example/*' => Http::response([])]);
        $restaurant = $this->restaurant(['address' => 'Rua A, 1', 'latitude' => -23.5, 'longitude' => -46.6]);

        // The old position belongs to the old address.
        $restaurant->update(['address' => 'Lugar nenhum']);
        $this->assertNull($restaurant->fresh()->latitude);

        $restaurant->update(['latitude' => -23.5, 'longitude' => -46.6]);
        $restaurant->update(['address' => null]);
        $this->assertNull($restaurant->fresh()->latitude);
        $this->assertNull($restaurant->fresh()->longitude);

        // Other changes keep it.
        $restaurant->update(['address' => 'Rua B, 2']);
        $restaurant->forceFill(['latitude' => -23.5, 'longitude' => -46.6])->save();
        $restaurant->update(['name' => 'Outro nome', 'price_level' => 3]);
        $this->assertSame(-23.5, $restaurant->fresh()->latitude);
    }

    public function test_a_lookup_that_finishes_after_another_address_change_is_dropped(): void
    {
        config(['services.geocoding.url' => 'https://geo.example/search']);
        $restaurant = $this->restaurant();
        // The address changes again while the lookup for the previous one is under way.
        Http::fake(function () use ($restaurant) {
            User::whereKey($restaurant->id)->update(['address' => 'Endereço novo']);

            return Http::response([['lat' => '-23.5614', 'lon' => '-46.6559']]);
        });

        $restaurant->update(['address' => 'Endereço antigo']);

        Http::assertSent(fn ($request) => $request['q'] === 'Endereço antigo');
        $this->assertSame('Endereço novo', $restaurant->fresh()->address);
        $this->assertNull($restaurant->fresh()->latitude);
    }

    public function test_only_restaurants_addresses_are_looked_up(): void
    {
        Queue::fake();

        $this->customer()->update(['address' => 'Rua do Cliente, 1']);
        $this->restaurant()->update(['name' => 'Sem endereço']);
        Queue::assertNothingPushed();

        $this->restaurant()->update(['address' => 'Rua A, 1']);
        Queue::assertPushed(GeocodeRestaurant::class, 1);
    }

    public function test_the_command_looks_up_addresses_saved_before(): void
    {
        $missing = $this->restaurant(['address' => 'Rua A, 1']);
        $this->restaurant(['address' => 'Rua B, 2', 'latitude' => -23.5, 'longitude' => -46.6]);
        $this->restaurant();
        Queue::fake();

        $this->artisan('restaurants:geocode')->assertSuccessful();

        Queue::assertPushed(GeocodeRestaurant::class, 1);
        Queue::assertPushed(GeocodeRestaurant::class, fn (GeocodeRestaurant $job) => $job->restaurantId === $missing->id);
    }
}
