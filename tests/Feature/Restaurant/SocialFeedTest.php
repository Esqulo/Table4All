<?php

namespace Tests\Feature\Restaurant;

use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\RestaurantPost;
use App\Models\User;
use App\Support\Emoji;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** The consumers' feed at /feed: posts from every public restaurant, newest first. */
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

    public function test_feed_lives_at_its_own_address_and_old_links_still_reach_it(): void
    {
        $this->restaurant(['name' => 'Bar do Zé']);
        $customer = $this->customer();

        $this->assertSame(url('/feed'), route('social.feed'));
        $this->actingAs($customer)->get('/feed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('social/feed'));
        // Its former addresses still lead there, search term included.
        foreach (['/vempro', '/painel'] as $former) {
            $this->get($former)->assertStatus(301)->assertRedirect('/feed');
            $this->get($former.'?q=bar')->assertStatus(301)->assertRedirect('/feed?q=bar');
        }
        // The restaurant's own page keeps its /vempro address.
        $this->actingAs($customer)->get('/vempro/bar-do-ze')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('profile/show'));
        $this->get('/feed/bar-do-ze')->assertNotFound();
        // The restaurant's own dashboard keeps its address.
        $this->assertSame(url('/restaurant/painel'), route('restaurant.dashboards.index'));
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

    public function test_only_restaurants_get_the_post_composer_on_the_feed(): void
    {
        $this->actingAs($this->restaurant())->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canPost', true)
                ->where('profilePublic', true));
        $this->actingAs($this->restaurant(['profile_public' => false]))->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canPost', true)
                ->where('profilePublic', false));
        $this->actingAs($this->customer())->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canPost', false)
                ->where('profilePublic', null));
    }

    public function test_a_restaurant_posts_from_the_feed_and_stays_there(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)
            ->from(route('social.feed'))
            ->post(route('restaurant.posts.store'), ['body' => 'Chopp em dobro hoje', 'request_key' => (string) Str::uuid()])
            ->assertRedirect(route('social.feed'));

        $this->actingAs($this->customer())->get(route('social.feed'))
            ->assertInertia(fn (Assert $page) => $page->where('posts.data.0.body', 'Chopp em dobro hoje'));
    }

    public function test_feed_requires_a_verified_account(): void
    {
        $this->get(route('social.feed'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('social.feed'))
            ->assertRedirect(route('verification.notice'));
    }
}
