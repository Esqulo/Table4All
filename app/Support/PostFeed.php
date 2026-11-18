<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\RestaurantPost;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shapes restaurant posts for the screen with their reactions and comment counts.
 * Three queries per page however many posts it has (no N+1).
 */
final class PostFeed
{
    /** Columns the feed reads from restaurant_posts. */
    public const COLUMNS = ['id', 'user_id', 'body', 'image', 'image_aspect', 'created_at'];

    /** Restaurant columns a feed of several restaurants needs (public fields only). */
    public const RESTAURANT_COLUMNS = ['id', 'name', 'handle', 'logo', 'account_type'];

    /**
     * @param  Collection<int, RestaurantPost>  $posts
     * @param  bool  $withRestaurant  add each post's restaurant (posts must have `restaurant` loaded)
     * @return list<array<string, mixed>>
     */
    public static function present(Collection $posts, ?User $viewer, bool $withRestaurant = false): array
    {
        if ($posts->isEmpty()) {
            return [];
        }

        $ids = array_values(array_map(fn (RestaurantPost $p): int => $p->id, $posts->all()));
        $reactions = self::reactionsFor($ids, $viewer);
        // Explicit alias: PostgreSQL names a bare COUNT(*) "count", SQLite "COUNT(*)".
        $comments = PostComment::whereIn('post_id', $ids)
            ->groupBy('post_id')
            ->selectRaw('post_id, COUNT(*) as total')
            ->pluck('total', 'post_id');

        return array_values($posts->map(function (RestaurantPost $post) use ($reactions, $comments, $viewer, $withRestaurant): array {
            $item = [
                'id' => $post->id,
                'body' => $post->body,
                'image_url' => $post->image_url,
                'image_aspect' => $post->image_aspect,
                'created_at' => $post->created_at?->toIso8601String(),
                'reactions' => $reactions[$post->id] ?? self::emptyReactions(),
                'comments_count' => (int) ($comments[$post->id] ?? 0),
            ];

            if ($withRestaurant) {
                $restaurant = $post->restaurant;
                $item['restaurant'] = [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'handle' => (string) $restaurant->handle,
                    'logo_url' => $restaurant->logo_url,
                    'banner_url' => null,
                ];
                $item['is_owner'] = $viewer !== null
                    && $viewer->account_type === AccountType::RESTAURANT
                    && $viewer->id === $restaurant->id;
            }

            return $item;
        })->all());
    }

    /**
     * Reaction summary of one post, as the reaction endpoints return it.
     *
     * @return array{summary: list<array{emoji: string, count: int}>, total: int, mine: string|null}
     */
    public static function reactionsOf(int $postId, ?User $viewer): array
    {
        return self::reactionsFor([$postId], $viewer)[$postId] ?? self::emptyReactions();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{summary: list<array{emoji: string, count: int}>, total: int, mine: string|null}>
     */
    private static function reactionsFor(array $ids, ?User $viewer): array
    {
        $rows = PostReaction::whereIn('post_id', $ids)
            ->groupBy('post_id', 'emoji')
            ->get(['post_id', 'emoji', DB::raw('COUNT(*) as total')]);

        $mine = $viewer === null ? collect() : PostReaction::whereIn('post_id', $ids)
            ->where('user_id', $viewer->id)
            ->pluck('emoji', 'post_id');

        $result = [];

        foreach ($rows->groupBy('post_id') as $postId => $group) {
            $summary = array_values($group
                ->map(fn ($r): array => ['emoji' => (string) $r->emoji, 'count' => (int) $r->getAttribute('total')])
                ->sortBy([['count', 'desc'], ['emoji', 'asc']])
                ->all());

            $result[(int) $postId] = [
                'summary' => $summary,
                'total' => array_sum(array_column($summary, 'count')),
                'mine' => isset($mine[$postId]) ? (string) $mine[$postId] : null,
            ];
        }

        return $result;
    }

    /** @return array{summary: list<array{emoji: string, count: int}>, total: int, mine: string|null} */
    private static function emptyReactions(): array
    {
        return ['summary' => [], 'total' => 0, 'mine' => null];
    }
}
