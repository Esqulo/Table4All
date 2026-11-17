<?php

namespace Tests\Feature\Restaurant;

use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\RestaurantPost;
use App\Models\RestaurantReview;
use App\Models\User;
use App\Support\Emoji;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Posts on the public profile: publishing, emoji reactions, comments, and the star rating. */
class SocialTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    private function newPost(User $restaurant, array $attributes = []): RestaurantPost
    {
        return RestaurantPost::create(['user_id' => $restaurant->id, 'body' => 'Chopp em dobro hoje!', ...$attributes]);
    }

    private function react(User $user, RestaurantPost $post, string $emoji, int $times = 1): void
    {
        PostReaction::create(['post_id' => $post->id, 'user_id' => $user->id, 'emoji' => $emoji]);

        for ($i = 1; $i < $times; $i++) {
            PostReaction::create(['post_id' => $post->id, 'user_id' => $this->customer()->id, 'emoji' => $emoji]);
        }
    }

    /** A real 1x1 PNG, so uploads don't depend on the GD extension. */
    private function png(string $name = 'p.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));
    }

    // ── publishing ───────────────────────────────────────────────────────────

    public function test_restaurant_publishes_text_picture_or_both(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.posts.store'), ['body' => '  Samba sábado!  '])
            ->assertSessionHasNoErrors();
        $this->actingAs($restaurant)->post(route('restaurant.posts.store'), ['image' => $this->png(), 'image_aspect' => 'square'])
            ->assertSessionHasNoErrors();
        $this->actingAs($restaurant)->post(route('restaurant.posts.store'), ['body' => 'Feijoada', 'image' => $this->png(), 'image_aspect' => 'landscape'])
            ->assertSessionHasNoErrors();

        $posts = RestaurantPost::orderBy('id')->get();
        $this->assertCount(3, $posts);
        $this->assertSame('Samba sábado!', $posts[0]->body);
        $this->assertNull($posts[0]->image);
        $this->assertNull($posts[1]->body);
        $this->assertSame('square', $posts[1]->image_aspect);
        Storage::disk('public')->assertExists($posts[1]->image);
        $this->assertStringStartsWith('posts/', $posts[2]->image);
        $this->assertTrue($posts->every(fn (RestaurantPost $p) => $p->user_id === $restaurant->id));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPosts(): array
    {
        return [
            'empty' => [['body' => '   '], 'body'],
            'too long' => [['body' => str_repeat('a', RestaurantPost::BODY_MAX + 1)], 'body'],
            'picture without format' => [['image' => 'PNG'], 'image_aspect'],
            'unknown format' => [['image' => 'PNG', 'image_aspect' => 'panorama'], 'image_aspect'],
            'not a picture' => [['image' => 'PDF', 'image_aspect' => 'square'], 'image'],
        ];
    }

    #[DataProvider('invalidPosts')]
    public function test_invalid_posts_are_rejected(array $payload, string $field): void
    {
        $restaurant = $this->restaurant();
        $payload = array_map(fn ($v) => match ($v) {
            'PNG' => $this->png(),
            'PDF' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            default => $v,
        }, $payload);

        $this->actingAs($restaurant)->post(route('restaurant.posts.store'), $payload)->assertSessionHasErrors($field);

        $this->assertSame(0, RestaurantPost::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_the_restaurant_publishes(): void
    {
        $restaurant = $this->restaurant();

        foreach ([$this->waiterOf($restaurant), $this->customer()] as $user) {
            $this->actingAs($user)->post(route('restaurant.posts.store'), ['body' => 'oi'])
                ->assertRedirect(route('dashboard'));
        }

        auth()->logout();
        $this->post(route('restaurant.posts.store'), ['body' => 'oi'])->assertRedirect(route('login'));
        $this->assertSame(0, RestaurantPost::count());
    }

    public function test_deleting_a_post_removes_its_picture_reactions_and_comments(): void
    {
        $restaurant = $this->restaurant();
        Storage::disk('public')->put('posts/a.webp', 'x');
        $post = $this->newPost($restaurant, ['image' => 'posts/a.webp', 'image_aspect' => 'square']);
        $this->react($this->customer(), $post, '🍺');
        PostComment::create(['post_id' => $post->id, 'user_id' => $this->customer()->id, 'body' => 'top']);

        $this->actingAs($restaurant)->delete(route('restaurant.posts.destroy', $post))
            ->assertRedirect(route('restaurant-profile.show', $restaurant->handle));

        $this->assertSame(0, RestaurantPost::count());
        $this->assertSame(0, PostReaction::count());
        $this->assertSame(0, PostComment::count());
        Storage::disk('public')->assertMissing('posts/a.webp');
    }

    public function test_another_restaurant_cannot_delete_the_post(): void
    {
        $post = $this->newPost($this->restaurant());

        $this->actingAs($this->restaurant())->delete(route('restaurant.posts.destroy', $post))->assertForbidden();
        $this->assertSame(1, RestaurantPost::count());
    }

    // ── feed ─────────────────────────────────────────────────────────────────

    public function test_profile_feed_shows_the_restaurants_posts_newest_first_with_reactions(): void
    {
        $restaurant = $this->restaurant();
        $older = $this->newPost($restaurant, ['body' => 'velho']);
        $newer = $this->newPost($restaurant, ['body' => 'novo']);
        $this->newPost($this->restaurant(), ['body' => 'de outro']);
        $viewer = $this->customer();
        $this->react($viewer, $newer, '🔥');
        $this->react($this->customer(), $newer, '🍺', 2);
        PostComment::create(['post_id' => $newer->id, 'user_id' => $viewer->id, 'body' => 'boa']);

        $this->actingAs($viewer)->get(route('restaurant-profile.show', $restaurant->handle))
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 2)
                ->where('posts.data.0.id', $newer->id)
                ->where('posts.data.0.body', 'novo')
                ->where('posts.data.0.reactions', [
                    'summary' => [['emoji' => '🍺', 'count' => 2], ['emoji' => '🔥', 'count' => 1]],
                    'total' => 3,
                    'mine' => '🔥',
                ])
                ->where('posts.data.0.comments_count', 1)
                ->where('posts.data.1.id', $older->id)
                ->where('posts.data.1.reactions', ['summary' => [], 'total' => 0, 'mine' => null])
                ->where('canInteract', true)
                ->where('suggestedReactions', Emoji::SUGGESTED));
    }

    public function test_feed_is_paginated_for_infinite_scroll(): void
    {
        $restaurant = $this->restaurant();

        for ($i = 1; $i <= 12; $i++) {
            $this->newPost($restaurant, ['body' => "post $i"]);
        }

        $first = $this->get(route('restaurant-profile.show', $restaurant->handle));
        $first->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 10)
            ->where('posts.data.0.body', 'post 12'));

        $next = $first->viewData('page')['scrollProps']['posts']['nextPage'];
        $this->assertNotNull($next);

        $this->get(route('restaurant-profile.show', [$restaurant->handle, 'cursor' => $next]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 2)
                ->where('posts.data.1.body', 'post 1'));
    }

    public function test_feed_query_count_does_not_grow_with_posts(): void
    {
        $restaurant = $this->restaurant();
        $viewer = $this->customer();
        $queries = function () use ($restaurant, $viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->get(route('restaurant-profile.show', $restaurant->handle))->assertOk();

            return count(DB::getQueryLog());
        };

        $this->react($viewer, $this->newPost($restaurant), '🍺');
        $withOne = $queries();

        for ($i = 0; $i < 9; $i++) {
            $post = $this->newPost($restaurant);
            $this->react($this->customer(), $post, '🔥');
            PostComment::create(['post_id' => $post->id, 'user_id' => $viewer->id, 'body' => 'x']);
        }

        $this->assertSame($withOne, $queries());
    }

    // ── reactions ────────────────────────────────────────────────────────────

    public function test_reacting_again_replaces_the_emoji_and_unreacting_removes_it(): void
    {
        $post = $this->newPost($this->restaurant());
        $customer = $this->customer();

        $this->actingAs($customer)->putJson(route('posts.react', $post), ['emoji' => '🍺'])
            ->assertOk()
            ->assertExactJson(['summary' => [['emoji' => '🍺', 'count' => 1]], 'total' => 1, 'mine' => '🍺']);

        $this->actingAs($customer)->putJson(route('posts.react', $post), ['emoji' => '😋'])
            ->assertOk()
            ->assertExactJson(['summary' => [['emoji' => '😋', 'count' => 1]], 'total' => 1, 'mine' => '😋']);

        $this->actingAs($customer)->deleteJson(route('posts.unreact', $post))
            ->assertOk()
            ->assertExactJson(['summary' => [], 'total' => 0, 'mine' => null]);

        $this->assertSame(0, PostReaction::count());
    }

    public function test_any_emoji_is_accepted_and_variants_count_as_the_same_one(): void
    {
        $post = $this->newPost($this->restaurant());

        foreach (['❤', '❤️', '🇧🇷', '👨‍👩‍👧', '👍🏽'] as $emoji) {
            $this->actingAs($this->customer())->putJson(route('posts.react', $post), ['emoji' => $emoji])->assertOk();
        }

        $summary = collect($this->actingAs($this->customer())->deleteJson(route('posts.unreact', $post))->json('summary'));
        $this->assertSame(2, $summary->firstWhere('emoji', "❤\u{FE0F}")['count']);
        $this->assertCount(4, $summary);
    }

    /** @return array<string, array{string}> */
    public static function notOneEmoji(): array
    {
        return [
            'empty' => [''],
            'text' => ['legal'],
            'two emoji' => ['🍺🍺'],
            'emoji and text' => ['🍺 top'],
            'html' => ['<b>'],
            'digit' => ['1'],
        ];
    }

    #[DataProvider('notOneEmoji')]
    public function test_reaction_must_be_exactly_one_emoji(string $emoji): void
    {
        $post = $this->newPost($this->restaurant());

        $this->actingAs($this->customer())->putJson(route('posts.react', $post), ['emoji' => $emoji])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('emoji');

        $this->assertSame(0, PostReaction::count());
    }

    public function test_guests_and_unverified_users_cannot_react(): void
    {
        $post = $this->newPost($this->restaurant());

        $this->putJson(route('posts.react', $post), ['emoji' => '🍺'])->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())
            ->putJson(route('posts.react', $post), ['emoji' => '🍺'])
            ->assertForbidden();

        $this->assertSame(0, PostReaction::count());
    }

    public function test_guests_see_reactions_but_cannot_interact(): void
    {
        $restaurant = $this->restaurant();
        $this->react($this->customer(), $this->newPost($restaurant), '🎉');

        $this->get(route('restaurant-profile.show', $restaurant->handle))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canInteract', false)
                ->where('posts.data.0.reactions.total', 1)
                ->where('posts.data.0.reactions.mine', null));
    }

    // ── comments ─────────────────────────────────────────────────────────────

    public function test_signed_in_users_comment_and_the_post_page_lists_them(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $post = $this->newPost($restaurant);
        $customer = $this->customer();
        $customer->update(['name' => 'Ana', 'phone' => '11988887777']);

        $this->actingAs($customer)->post(route('posts.comments.store', $post), ['body' => '  Vou levar a galera!  '])
            ->assertSessionHasNoErrors();
        $this->actingAs($restaurant)->post(route('posts.comments.store', $post), ['body' => 'Te esperamos!'])
            ->assertSessionHasNoErrors();

        // Other people never get the author's email or phone (the author's own page carries their auth.user).
        $this->actingAs($this->customer())->get(route('restaurant-profile.post', ['bar-do-ze', $post->id]))
            ->assertOk()
            ->assertDontSee($customer->email, false)
            ->assertDontSee('11988887777', false);

        $this->actingAs($customer)->get(route('restaurant-profile.post', ['bar-do-ze', $post->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('profile/post')
                ->where('post.id', $post->id)
                ->where('commentsTotal', 2)
                ->where('comments.0.body', 'Vou levar a galera!')
                ->where('comments.0.author', ['name' => 'Ana', 'avatar_url' => null, 'is_restaurant' => false])
                ->where('comments.0.can_delete', true)
                ->where('comments.1.author.is_restaurant', true)
                ->where('comments.1.can_delete', false));
    }

    public function test_invalid_comments_are_rejected(): void
    {
        $post = $this->newPost($this->restaurant());
        $customer = $this->customer();

        $this->actingAs($customer)->post(route('posts.comments.store', $post), ['body' => '   '])->assertSessionHasErrors('body');
        $this->actingAs($customer)->post(route('posts.comments.store', $post), ['body' => str_repeat('a', PostComment::BODY_MAX + 1)])
            ->assertSessionHasErrors('body');

        auth()->logout();
        $this->post(route('posts.comments.store', $post), ['body' => 'oi'])->assertRedirect(route('login'));
        $this->assertSame(0, PostComment::count());
    }

    public function test_author_and_restaurant_can_delete_a_comment_but_nobody_else(): void
    {
        $restaurant = $this->restaurant();
        $post = $this->newPost($restaurant);
        $author = $this->customer();
        $comment = fn () => PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'body' => 'spam']);

        $first = $comment();
        foreach ([$this->customer(), $this->waiterOf($restaurant), $this->restaurant()] as $stranger) {
            $this->actingAs($stranger)->delete(route('comments.destroy', $first))->assertForbidden();
        }
        $this->assertNotNull($first->fresh());

        $this->actingAs($author)->delete(route('comments.destroy', $first))->assertRedirect();
        $this->assertNull($first->fresh());

        $second = $comment();
        $this->actingAs($restaurant)->delete(route('comments.destroy', $second))->assertRedirect();
        $this->assertNull($second->fresh());
    }

    // ── post page ────────────────────────────────────────────────────────────

    public function test_post_page_belongs_to_its_restaurant_and_has_a_link_preview(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $post = $this->newPost($restaurant, ['body' => "Roda de samba\nsábado às 20h", 'image' => 'posts/s.webp', 'image_aspect' => 'portrait']);
        $other = $this->newPost($this->restaurant(['name' => 'Outro']));

        $this->get(route('restaurant-profile.post', ['bar-do-ze', $post->id]))
            ->assertOk()
            ->assertSee('<meta property="og:description" content="Roda de samba sábado às 20h">', false)
            ->assertSee('<meta property="og:image" content="'.Storage::disk('public')->url('posts/s.webp').'">', false);

        $this->get(route('restaurant-profile.post', ['bar-do-ze', $other->id]))->assertNotFound();
        $this->get(route('restaurant-profile.post', ['bar-do-ze', 999999]))->assertNotFound();
        $this->get(route('restaurant-profile.post', ['Bar-Do-Ze', $post->id]))
            ->assertRedirect(route('restaurant-profile.post', ['bar-do-ze', $post->id]));
    }

    // ── hidden profile ───────────────────────────────────────────────────────

    public function test_posts_of_a_hidden_profile_cannot_be_seen_or_touched(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé', 'profile_public' => false]);
        $post = $this->newPost($restaurant);
        $customer = $this->customer();

        $this->actingAs($customer)->get(route('restaurant-profile.post', ['bar-do-ze', $post->id]))->assertNotFound();
        $this->actingAs($customer)->putJson(route('posts.react', $post), ['emoji' => '🍺'])->assertNotFound();
        $this->actingAs($customer)->post(route('posts.comments.store', $post), ['body' => 'oi'])->assertNotFound();
        $this->assertSame(0, PostReaction::count() + PostComment::count());

        // The owner still sees and uses it.
        $this->actingAs($restaurant)->get(route('restaurant-profile.post', ['bar-do-ze', $post->id]))->assertOk();
        $this->actingAs($restaurant)->putJson(route('posts.react', $post), ['emoji' => '🎉'])->assertOk();
    }

    // ── rating ───────────────────────────────────────────────────────────────

    public function test_profile_shows_the_average_rating_and_review_count(): void
    {
        $restaurant = $this->restaurant();
        $other = $this->restaurant();

        foreach ([5, 4, 4] as $rating) {
            RestaurantReview::create(['restaurant_id' => $restaurant->id, 'user_id' => $this->customer()->id, 'rating' => $rating]);
        }
        RestaurantReview::create(['restaurant_id' => $other->id, 'user_id' => $this->customer()->id, 'rating' => 1]);

        $this->get(route('restaurant-profile.show', $restaurant->handle))
            ->assertInertia(fn (Assert $page) => $page->where('rating', ['average' => 4.3, 'count' => 3]));
    }

    public function test_rating_is_zero_without_reviews(): void
    {
        $restaurant = $this->restaurant();

        $this->get(route('restaurant-profile.show', $restaurant->handle))
            ->assertInertia(fn (Assert $page) => $page->where('rating', ['average' => 0, 'count' => 0]));
    }
}
