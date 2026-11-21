<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\RestaurantPost;
use App\Models\RestaurantStory;
use App\Models\User;
use App\Support\Emoji;
use App\Support\PostFeed;
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

    /**
     * Newest first for now; a ranking algorithm comes later. Restaurants that
     * hid their profile are left out, their own posts included.
     *
     * Finding restaurants has its own page now (/descobrir, DiscoverController): the
     * feed's former search addresses (?explorar=1, ?q=) lead there, term included.
     */
    public function index(Request $request, VideoTranscoder $transcoder): Response|RedirectResponse
    {
        if ($request->has('explorar') || $request->filled('q')) {
            $q = $request->query('q');

            return to_route('social.discover', is_string($q) && trim($q) !== '' ? ['q' => trim($q)] : []);
        }

        $viewer = $request->user();

        return Inertia::render('social/feed', [
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
            'canInteract' => $viewer !== null && $viewer->hasVerifiedEmail(),
            // Restaurants post from the feed too (PostController::store); a hidden profile's posts stay out of it.
            'canPost' => $isRestaurant = $viewer?->account_type === AccountType::RESTAURANT,
            'profilePublic' => $isRestaurant ? (bool) ($viewer->profile_public ?? true) : null, // true is the column default
            'suggestedReactions' => Emoji::SUGGESTED,
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

        if ($request->user()->account_type === AccountType::ADMIN) {
            return to_route('admin.overview');
        }

        return Inertia::render('dashboard');
    }
}
