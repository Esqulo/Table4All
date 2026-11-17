<?php

namespace Tests\Feature\Restaurant;

use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\RestaurantPost;
use App\Models\RestaurantReview;
use App\Models\User;
use App\Support\Emoji;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** The consumers' feed at /vempro: posts from every public restaurant, newest first. */
class SocialFeedTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    private function newPost(User $restaurant, string $body = 'Promo!'): RestaurantPost
    {
        return RestaurantPost::create(['user_id' => $restaurant->id, 'body' => $body]);
    }

    public function test_feed_mixes_every_public_restaurant_newest_first(): void
    {
        $bar = $this->restaurant(['name' => 'Bar do Zé', 'logo' => 'logos/z.webp', 'phone' => '11999990000']);
        $pizza = $this->restaurant(['name' => 'Pizzaria Bella']);
        $old = $this->newPost($bar, 'primeiro');
        $mid = $this->newPost($pizza, 'segundo');
        $new = $this->newPost($bar, 'terceiro');
        $customer = $this->customer();
        PostReaction::create(['post_id' => $mid->id, 'user_id' => $customer->id, 'emoji' => '🍺']);
        PostComment::create(['post_id' => $mid->id, 'user_id' => $customer->id, 'body' => 'boa']);

        $this->actingAs($customer)->get(route('social.feed'))
            ->assertOk()
            ->assertDontSee($bar->email, false)
            ->assertDontSee('11999990000', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('social/feed')
                ->has('posts.data', 3)
                ->where('posts.data.0.id', $new->id)
                ->where('posts.data.1.id', $mid->id)
                ->where('posts.data.2.id', $old->id)
                ->where('posts.data.0.restaurant', [
                    'id' => $bar->id,
                    'name' => 'Bar do Zé',
                    'handle' => 'bar-do-ze',
                    'logo_url' => Storage::disk('public')->url('logos/z.webp'),
                    'banner_url' => null,
                ])
                ->where('posts.data.1.restaurant.handle', 'pizzaria-bella')
                ->where('posts.data.1.reactions.mine', '🍺')
                ->where('posts.data.1.comments_count', 1)
                ->where('posts.data.0.is_owner', false)
                ->where('canInteract', true)
                ->where('suggestedReactions', Emoji::SUGGESTED));
    }

    public function test_hidden_profiles_stay_out_of_the_feed(): void
    {
        $visible = $this->restaurant();
        $hidden = $this->restaurant(['profile_public' => false]);
        $shown = $this->newPost($visible);
        $this->newPost($hidden);

        // Not even the hidden restaurant sees its own posts here: the feed is what consumers see.
        foreach ([$this->customer(), $hidden] as $viewer) {
            $this->actingAs($viewer)->get(route('social.feed'))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('posts.data', 1)
                    ->where('posts.data.0.id', $shown->id));
        }
    }

    public function test_the_restaurant_can_manage_its_own_posts_from_the_feed(): void
    {
        $restaurant = $this->restaurant();
        $mine = $this->newPost($restaurant);
        $this->newPost($this->restaurant());

        $this->actingAs($restaurant)->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.data.1.id', $mine->id)
                ->where('posts.data.1.is_owner', true)
                ->where('posts.data.0.is_owner', false));

        // Deleting from the feed keeps the restaurant on the feed.
        $this->actingAs($restaurant)
            ->from(route('social.feed'))
            ->delete(route('restaurant.posts.destroy', $mine))
            ->assertRedirect(route('social.feed'));
    }

    public function test_deleting_from_the_post_page_goes_to_the_profile(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $post = $this->newPost($restaurant);

        $this->actingAs($restaurant)
            ->from(route('restaurant-profile.post', ['bar-do-ze', $post->id]))
            ->delete(route('restaurant.posts.destroy', $post))
            ->assertRedirect(route('restaurant-profile.show', 'bar-do-ze'));
    }

    public function test_feed_is_paginated_for_infinite_scroll(): void
    {
        $restaurants = [$this->restaurant(), $this->restaurant()];

        for ($i = 1; $i <= 13; $i++) {
            $this->newPost($restaurants[$i % 2], "post $i");
        }

        $first = $this->actingAs($this->customer())->get(route('social.feed'));
        $first->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 10)
            ->where('posts.data.0.body', 'post 13'));

        $next = $first->viewData('page')['scrollProps']['posts']['nextPage'];

        $this->get(route('social.feed', ['cursor' => $next]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 3)
                ->where('posts.data.2.body', 'post 1'));
    }

    public function test_feed_query_count_does_not_grow_with_posts_or_restaurants(): void
    {
        $viewer = $this->customer();
        $queries = function () use ($viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->get(route('social.feed'))->assertOk();

            return count(DB::getQueryLog());
        };

        $this->newPost($this->restaurant());
        $withOne = $queries();

        for ($i = 0; $i < 9; $i++) {
            $post = $this->newPost($this->restaurant());
            PostReaction::create(['post_id' => $post->id, 'user_id' => $this->customer()->id, 'emoji' => '🔥']);
            PostComment::create(['post_id' => $post->id, 'user_id' => $viewer->id, 'body' => 'x']);
        }

        $this->assertSame($withOne, $queries());
    }

    public function test_empty_feed(): void
    {
        $this->actingAs($this->customer())->get(route('social.feed'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('posts.data', 0));
    }

    public function test_feed_requires_a_verified_account(): void
    {
        $this->get(route('social.feed'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('social.feed'))
            ->assertRedirect(route('verification.notice'));
    }

    // ── search ───────────────────────────────────────────────────────────────

    /** @return list<string> names of the restaurants found for $q */
    private function search(string $q): array
    {
        $results = $this->actingAs($this->customer())->get(route('social.feed', ['q' => $q]))
            ->viewData('page')['props']['searchResults'];

        return $results === null ? [] : array_column($results, 'name');
    }

    public function test_search_finds_restaurants_by_name_or_handle(): void
    {
        $this->restaurant(['name' => 'Bar do Zé']);
        $this->restaurant(['name' => 'Pizzaria Bella']);
        $this->restaurant(['name' => 'Zé Lanches'])->update(['handle' => 'lanchonete']);

        $this->assertSame(['Bar do Zé', 'Zé Lanches'], $this->search('zé'));
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
        $this->assertSame(['Cafeteria Central', 'Grão & Prosa Café'], $this->search('cafe'));
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
        $this->restaurant(['name' => 'Bar']);

        $this->assertSame(['Bar', 'A Casa do Bar'], $this->search('bar'));
    }

    public function test_search_only_lists_public_restaurants_with_public_fields(): void
    {
        $bar = $this->restaurant(['name' => 'Bar Visível', 'phone' => '11999990000', 'logo' => 'logos/b.webp']);
        $this->restaurant(['name' => 'Bar Oculto', 'profile_public' => false]);
        $customer = $this->customer();
        $customer->update(['name' => 'Bar Cliente']);
        foreach ([5, 4] as $rating) {
            RestaurantReview::create(['restaurant_id' => $bar->id, 'user_id' => $this->customer()->id, 'rating' => $rating]);
        }

        $this->actingAs($customer)->get(route('social.feed', ['q' => 'bar']))
            ->assertDontSee($bar->email, false)
            ->assertDontSee('11999990000', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('q', 'bar')
                ->where('searchResults', [[
                    'id' => $bar->id,
                    'name' => 'Bar Visível',
                    'handle' => 'bar-visivel',
                    'logo_url' => Storage::disk('public')->url('logos/b.webp'),
                    'rating' => ['average' => 4.5, 'count' => 2],
                ]]));
    }

    public function test_short_or_empty_terms_do_not_search(): void
    {
        $this->restaurant(['name' => 'Bar']);

        foreach (['', ' ', 'b', '@'] as $q) {
            $this->actingAs($this->customer())->get(route('social.feed', ['q' => $q]))
                ->assertInertia(fn (Assert $page) => $page->where('searchResults', null));
        }
    }

    public function test_like_wildcards_in_the_term_are_literal(): void
    {
        $this->restaurant(['name' => 'Bar 50% off']);
        $this->restaurant(['name' => 'Bar 500']);

        $this->assertSame(['Bar 50% off'], $this->search('50%'));
        $this->assertSame([], $this->search('b_r'));
    }

    public function test_typing_reloads_only_the_search_not_the_posts(): void
    {
        $this->newPost($this->restaurant(['name' => 'Bar do Zé']));

        $this->actingAs($this->customer())->get(route('social.feed', ['q' => 'bar']))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly(['q', 'searchResults'], fn (Assert $reload) => $reload
                    ->missing('posts')
                    ->missing('restaurants')
                    ->has('searchResults', 1)));
    }

    // ── browse strip ─────────────────────────────────────────────────────────

    public function test_strip_lists_public_restaurants_most_recently_active_first(): void
    {
        $quiet = $this->restaurant(['name' => 'A Sem Posts']);
        $older = $this->restaurant(['name' => 'B Postou Antes']);
        $newer = $this->restaurant(['name' => 'C Postou Agora']);
        $this->restaurant(['name' => 'Oculto', 'profile_public' => false]);
        $this->newPost($older);
        $this->newPost($newer);

        $this->actingAs($this->customer())->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('restaurants', 3)
                ->where('restaurants.0.id', $newer->id)
                ->where('restaurants.1.id', $older->id)
                ->where('restaurants.2.id', $quiet->id));
    }
}
