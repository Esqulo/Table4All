<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\RestaurantPost;
use App\Models\RestaurantStory;
use App\Models\User;
use App\Support\Emoji;
use App\Support\PostFeed;
use App\Support\RestaurantDirectory;
use App\Support\StoryFeed;
use App\Support\Video\VideoTranscoder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The social network as consumers see it (/feed): posts from every public restaurant. */
class SocialFeedController extends Controller
{
    /** Posts per page (infinite scroll). */
    private const PER_PAGE = 10;

    /** Restaurants per page of the list to browse (infinite scroll). */
    private const DISCOVER_PER_PAGE = 12;

    /**
     * Newest first for now; a ranking algorithm comes later. Restaurants that
     * hid their profile are left out, their own posts included.
     *
     * With ?explorar=1 (the search box was focused) the page lists restaurants to
     * browse instead of the posts. It's in the address, not just on screen, so
     * the browser's back button returns to the list as it was (Inertia restores
     * the loaded pages and the scroll position from the history entry).
     */
    public function index(Request $request, VideoTranscoder $transcoder): Response
    {
        $viewer = $request->user();
        $exploring = $request->boolean('explorar');

        $browse = $exploring ? [
            'discover' => Inertia::scroll(fn () => RestaurantDirectory::discover(self::DISCOVER_PER_PAGE, 'pagina')),
        ] : [
            'posts' => Inertia::scroll(function () use ($viewer) {
                $page = RestaurantPost::whereIn(
                    'user_id',
                    User::select('id')
                        ->where('account_type', AccountType::RESTAURANT)
                        ->where('profile_public', true),
                )
                    ->with(['restaurant' => fn ($q) => $q->select(PostFeed::RESTAURANT_COLUMNS)])
                    ->orderByDesc('id')
                    ->cursorPaginate(self::PER_PAGE, PostFeed::COLUMNS);

                return $page->setCollection(collect(PostFeed::present($page->getCollection(), $viewer, withRestaurant: true)));
            }),
            // "Hoje tem!": stories that disappear after 24 hours. Reloaded alone while a video converts.
            'stories' => fn () => StoryFeed::forViewer($viewer),
        ];

        return Inertia::render('social/feed', [
            ...$browse,
            'exploring' => $exploring,
            'canInteract' => $viewer !== null && $viewer->hasVerifiedEmail(),
            // Restaurants post from the feed too (PostController::store); a hidden profile's posts stay out of it.
            'canPost' => $isRestaurant = $viewer?->account_type === AccountType::RESTAURANT,
            'profilePublic' => $isRestaurant ? (bool) ($viewer->profile_public ?? true) : null, // true is the column default
            'suggestedReactions' => Emoji::SUGGESTED,
            // Search by name or @handle. The page reloads only these two props while typing.
            'q' => $q = trim((string) $request->query('q', '')),
            'searchResults' => fn () => mb_strlen($q) >= RestaurantDirectory::MIN_TERM
                ? RestaurantDirectory::search($q)
                : null,
            'storyLimits' => $isRestaurant ? [
                'maxVideoSeconds' => RestaurantStory::MAX_VIDEO_SECONDS,
                'maxVideoMb' => RestaurantStory::MAX_VIDEO_KB / 1024,
                'maxImageMb' => RestaurantStory::MAX_IMAGE_KB / 1024,
                'videoEnabled' => $transcoder->available(),
            ] : null,
        ]);
    }

    /** Where signed-in users land. Customers have no dashboard: their home is the feed. */
    public function home(Request $request): Response|RedirectResponse
    {
        if ($request->user()->account_type === AccountType::CUSTOMER) {
            return to_route('social.feed');
        }

        return Inertia::render('dashboard');
    }
}
