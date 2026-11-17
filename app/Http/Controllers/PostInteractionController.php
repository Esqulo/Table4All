<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\RestaurantPost;
use App\Models\User;
use App\Support\Emoji;
use App\Support\Idempotency;
use App\Support\PostFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** What any signed-in user does with a restaurant's posts: react with an emoji and comment. */
class PostInteractionController extends Controller
{
    /**
     * Sets the viewer's reaction (replacing a previous emoji). Answers JSON so the
     * feed updates in place without reloading the pages already scrolled.
     */
    public function react(Request $request, RestaurantPost $post): JsonResponse
    {
        $user = $request->user();
        $this->ensureVisible($post, $user);

        $emoji = Emoji::normalize((string) $request->input('emoji'));

        if ($emoji === null) {
            throw ValidationException::withMessages(['emoji' => __('social.error_emoji')]);
        }

        // Upsert on (post, user): a retry or a double tap leaves one reaction.
        PostReaction::upsert(
            [['post_id' => $post->id, 'user_id' => $user->id, 'emoji' => $emoji, 'created_at' => now(), 'updated_at' => now()]],
            ['post_id', 'user_id'],
            ['emoji', 'updated_at'],
        );

        return response()->json(PostFeed::reactionsOf($post->id, $user));
    }

    public function unreact(Request $request, RestaurantPost $post): JsonResponse
    {
        $user = $request->user();
        $this->ensureVisible($post, $user);

        PostReaction::where('post_id', $post->id)->where('user_id', $user->id)->delete();

        return response()->json(PostFeed::reactionsOf($post->id, $user));
    }

    public function storeComment(Request $request, RestaurantPost $post): RedirectResponse
    {
        $user = $request->user();
        $this->ensureVisible($post, $user);

        /** @var array{body: string} $data */
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.PostComment::BODY_MAX],
        ]);

        Idempotency::once($request, 'post-comment:'.$post->id.':'.$user->id, function () use ($post, $user, $data): void {
            PostComment::create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => trim($data['body'])]);
        });

        return back();
    }

    /** The author removes their comment; the restaurant moderates comments on its posts. */
    public function destroyComment(Request $request, PostComment $comment): RedirectResponse
    {
        $user = $request->user();
        $restaurantId = (int) RestaurantPost::whereKey($comment->post_id)->value('user_id');
        $isPostOwner = $user->account_type === AccountType::RESTAURANT && $user->id === $restaurantId;

        abort_unless($comment->user_id === $user->id || $isPostOwner, 403);

        $comment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'social.msg_comment_deleted']);

        return back();
    }

    /** Posts of a hidden profile don't exist for anyone but the restaurant. */
    private function ensureVisible(RestaurantPost $post, User $viewer): void
    {
        $restaurant = User::whereKey($post->user_id)->first(['id', 'account_type', 'profile_public']);

        abort_unless($restaurant?->publicProfileVisibleTo($viewer), 404);
    }
}
