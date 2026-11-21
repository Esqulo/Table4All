<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Chat among the customers at a table: short messages and floating emoji reactions. */
class TableChat
{
    public const MAX_LENGTH = 140;

    /** Messages sent to a screen: enough to follow the conversation, light on mobile data. */
    public const HISTORY = 50;

    /** Emoji a customer can send flying across the table's screens. */
    public const REACTIONS = ['🍻', '😂', '❤️', '🔥', '👏', '🎉'];

    /**
     * How long a reaction stays in the feed. Screens poll every few seconds, so
     * each one sees it at least once; it is gone before a reopened page replays it.
     */
    public const REACTION_WINDOW_SECONDS = 10;

    public static function send(int $tableId, int $userId, string $body): void
    {
        DB::table('table_messages')->insert([
            'restaurant_table_id' => $tableId,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    public static function react(int $tableId, int $userId, string $emoji): void
    {
        $now = now();

        DB::table('table_reactions')->insert([
            'restaurant_table_id' => $tableId,
            'user_id' => $userId,
            'emoji' => $emoji,
            'created_at' => $now,
        ]);

        // Reactions are fleeting: drop the table's old ones instead of keeping them forever.
        DB::table('table_reactions')
            ->where('restaurant_table_id', $tableId)
            ->where('created_at', '<', $now->copy()->subMinute())
            ->delete();
    }

    /**
     * The table's latest messages (oldest first) and the reactions still flying.
     *
     * @return array{
     *     messages: array<int, array{id: int, name: string, body: string, mine: bool, at: string}>,
     *     reactions: array<int, array{id: int, emoji: string, mine: bool}>
     * }
     */
    public static function feed(int $tableId, int $viewerId): array
    {
        $messages = DB::table('table_messages')
            ->where('table_messages.restaurant_table_id', $tableId)
            ->join('users', 'users.id', '=', 'table_messages.user_id')
            ->orderByDesc('table_messages.id')
            ->limit(self::HISTORY)
            ->get(['table_messages.id', 'table_messages.user_id', 'table_messages.body', 'table_messages.created_at', 'users.name'])
            ->reverse()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'body' => (string) $row->body,
                'mine' => (int) $row->user_id === $viewerId,
                'at' => Carbon::parse($row->created_at, 'UTC')->toIso8601String(),
            ])
            ->values()
            ->all();

        $reactions = DB::table('table_reactions')
            ->where('restaurant_table_id', $tableId)
            ->where('created_at', '>=', now()->subSeconds(self::REACTION_WINDOW_SECONDS))
            ->orderBy('id')
            ->get(['id', 'user_id', 'emoji'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'emoji' => (string) $row->emoji,
                'mine' => (int) $row->user_id === $viewerId,
            ])
            ->all();

        return ['messages' => $messages, 'reactions' => $reactions];
    }
}
