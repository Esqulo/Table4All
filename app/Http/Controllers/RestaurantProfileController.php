<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Menu;
use App\Models\PostComment;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\RestaurantPost;
use App\Models\RestaurantReview;
use App\Models\User;
use App\Support\Emoji;
use App\Support\OpeningHours;
use App\Support\PostFeed;
use App\Support\RestaurantHandle;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Public profile of a restaurant at /vempro/{handle}: anyone can see it, signed in or not. */
class RestaurantProfileController extends Controller
{
    /** Posts per page of the profile feed (infinite scroll). */
    private const POSTS_PER_PAGE = 10;

    /** Comments shown on a post page, newest kept when there are more. */
    private const COMMENTS_SHOWN = 200;

    public function show(Request $request, string $handle): Response|RedirectResponse
    {
        $canonical = RestaurantHandle::normalize($handle);
        $restaurant = $this->findVisible($canonical, $request->user());

        if ($handle !== $canonical) {
            return redirect()->route('restaurant-profile.show', $canonical, 301);
        }

        $viewer = $request->user();
        $hours = RestaurantOpeningHour::where('user_id', $restaurant->id)
            ->orderBy('weekday')
            ->orderBy('opens_at')
            ->get(['weekday', 'opens_at', 'closes_at'])
            ->map(fn (RestaurantOpeningHour $h): array => [
                'weekday' => $h->weekday,
                'opens_at' => substr($h->opens_at, 0, 5),
                'closes_at' => substr($h->closes_at, 0, 5),
            ]);

        $now = now(config('app.business_timezone'));
        $url = route('restaurant-profile.show', $restaurant->handle);
        $images = $this->images($restaurant);

        return Inertia::render('profile/show', [
            ...$this->header($restaurant, $viewer),
            'hours' => $hours,
            // Null when no hours were set: "closed" would be a guess.
            'isOpen' => $hours->isEmpty() ? null : OpeningHours::isOpenAt($hours, $now),
            'today' => $now->dayOfWeek,
            'photos' => RestaurantPhoto::where('user_id', $restaurant->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'path']),
            'menus' => Menu::where('user_id', $restaurant->id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'rating' => RestaurantReview::summaryFor($restaurant->id),
            'posts' => Inertia::scroll(function () use ($restaurant, $viewer) {
                $page = RestaurantPost::where('user_id', $restaurant->id)
                    ->orderByDesc('id')
                    ->cursorPaginate(self::POSTS_PER_PAGE, PostFeed::COLUMNS);

                return $page->setCollection(collect(PostFeed::present($page->getCollection(), $viewer)));
            }),
            'url' => $url,
        ])->withViewData([
            'meta' => $restaurant->profile_public ? [
                'title' => $restaurant->name,
                'description' => __('venue_profile.meta_description', ['name' => $restaurant->name]),
                'image' => $images['banner'] ?? $images['logo'],
                'large_image' => $images['banner'] !== null,
                'url' => $url,
            ] : null,
        ]);
    }

    /** A single post with its comments, at /vempro/{handle}/p/{post}: the address people share. */
    public function showPost(Request $request, string $handle, RestaurantPost $post): Response|RedirectResponse
    {
        $canonical = RestaurantHandle::normalize($handle);
        $viewer = $request->user();
        $restaurant = $this->findVisible($canonical, $viewer);

        abort_unless($post->user_id === $restaurant->id, 404);

        if ($handle !== $canonical) {
            return redirect()->route('restaurant-profile.post', [$canonical, $post->id], 301);
        }

        $comments = PostComment::where('post_id', $post->id)
            ->with('author:id,name,avatar,account_type')
            ->orderByDesc('id')
            ->limit(self::COMMENTS_SHOWN)
            ->get(['id', 'post_id', 'user_id', 'body', 'created_at'])
            ->reverse()
            ->values();

        $isOwner = $this->isOwner($restaurant, $viewer);
        $url = route('restaurant-profile.post', [$restaurant->handle, $post->id]);
        $images = $this->images($restaurant);

        return Inertia::render('profile/post', [
            ...$this->header($restaurant, $viewer),
            'post' => PostFeed::present(new EloquentCollection([$post]), $viewer)[0],
            'comments' => $comments->map(fn (PostComment $c): array => [
                'id' => $c->id,
                'body' => $c->body,
                'created_at' => $c->created_at?->toIso8601String(),
                'author' => [
                    // Name and picture only: never the author's email or phone.
                    'name' => $c->author->name,
                    'avatar_url' => $c->author->avatar_url,
                    'is_restaurant' => $c->author->id === $restaurant->id,
                ],
                'can_delete' => $viewer !== null && ($viewer->id === $c->user_id || $isOwner),
            ]),
            'commentsTotal' => PostComment::where('post_id', $post->id)->count(),
            'url' => $url,
        ])->withViewData([
            'meta' => $restaurant->profile_public ? [
                'title' => $restaurant->name,
                'description' => $post->body !== null
                    ? Str::limit(Str::squish($post->body), 160)
                    : __('venue_profile.meta_description', ['name' => $restaurant->name]),
                'image' => $post->image_url ?? $images['banner'] ?? $images['logo'],
                'large_image' => $post->image_url !== null || $images['banner'] !== null,
                'url' => $url,
            ] : null,
        ]);
    }

    /** The restaurant behind $handle, or 404 when there is none or it is hidden from $viewer. */
    private function findVisible(string $handle, ?User $viewer): User
    {
        $restaurant = User::where('handle', $handle)
            ->where('account_type', AccountType::RESTAURANT)
            ->first(['id', 'name', 'logo', 'banner', 'handle', 'profile_public', 'account_type']);

        // A hidden profile doesn't exist for anyone but its owner, who sees what visitors would.
        abort_unless($restaurant?->publicProfileVisibleTo($viewer), 404);

        /** @var User $restaurant */
        return $restaurant;
    }

    private function isOwner(User $restaurant, ?User $viewer): bool
    {
        return $viewer !== null
            && $viewer->account_type === AccountType::RESTAURANT
            && $viewer->id === $restaurant->id;
    }

    /** @return array{logo: string|null, banner: string|null} */
    private function images(User $restaurant): array
    {
        return [
            'logo' => $restaurant->logo ? Storage::disk('public')->url($restaurant->logo) : null,
            'banner' => $restaurant->banner ? Storage::disk('public')->url($restaurant->banner) : null,
        ];
    }

    /**
     * Props shared by the profile and the post page.
     *
     * @return array<string, mixed>
     */
    private function header(User $restaurant, ?User $viewer): array
    {
        $images = $this->images($restaurant);

        return [
            // Only public fields: the owner row also carries email, phone and auth data.
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'handle' => $restaurant->handle,
                'logo_url' => $images['logo'],
                'banner_url' => $images['banner'],
            ],
            'isOwner' => $this->isOwner($restaurant, $viewer),
            'isPublic' => $restaurant->profile_public,
            'canInteract' => $viewer !== null && $viewer->hasVerifiedEmail(),
            'suggestedReactions' => Emoji::SUGGESTED,
        ];
    }
}
