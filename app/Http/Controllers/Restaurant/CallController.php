<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\TableCall;
use App\Support\TableAssignment;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The calls board: tables that called the waiter or asked for the bill, oldest first.
 * Staff mark a call answered and it leaves the board. Polled during service, so it is
 * one query whatever the number of calls (totals aggregated in SQL).
 */
class CallController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('restaurant/calls', [
            'calls' => fn () => self::openCalls($request->user()->effectiveRestaurantId()),
            'assignment' => TableAssignment::context($request->user()),
        ]);
    }

    /** Conditional: answering a call someone else just answered does nothing. */
    public function answer(Request $request, TableCall $call): RedirectResponse
    {
        abort_unless($call->user_id === $request->user()->effectiveRestaurantId(), 403);

        TableCall::whereKey($call->id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolution' => TableCall::ANSWERED,
                'resolved_by_user_id' => $request->user()->id,
                'updated_at' => now(),
            ]);

        return back();
    }

    /** How many calls are waiting: the sidebar's badge. */
    public static function openCount(int $restaurantId): int
    {
        return self::openQuery($restaurantId)->count();
    }

    /** @return list<array<string, mixed>> */
    private static function openCalls(int $restaurantId): array
    {
        $rows = self::openQuery($restaurantId)
            ->leftJoin('users as requester', 'requester.id', '=', 'table_calls.requested_by_user_id')
            ->leftJoin('users as waiter', 'waiter.id', '=', 'restaurant_tables.waiter_id')
            ->orderBy('table_calls.created_at')
            ->select([
                'table_calls.id',
                'table_calls.type',
                'table_calls.created_at',
                'restaurant_tables.id as table_id',
                'restaurant_tables.title as table_title',
                'requester.name as requested_by',
                'waiter.id as waiter_id',
                'waiter.name as waiter_name',
            ])
            // What the table owes: useful when it asks for the bill.
            ->selectSub(
                DB::table('restaurant_table_product as rtp')
                    ->whereColumn('rtp.restaurant_table_id', 'restaurant_tables.id')
                    ->selectRaw('COALESCE(SUM(rtp.price * rtp.quantity), 0)'),
                'total',
            )
            ->selectSub(
                DB::table('table_payments as tp')
                    ->whereColumn('tp.restaurant_table_id', 'restaurant_tables.id')
                    ->selectRaw('COALESCE(SUM(tp.amount), 0)'),
                'paid',
            )
            ->get();

        $calls = [];

        foreach ($rows as $row) {
            $calls[] = [
                'id' => (int) $row->id,
                'type' => $row->type,
                'created_at' => Carbon::parse($row->created_at, 'UTC')->toIso8601String(),
                'table' => ['id' => (int) $row->table_id, 'title' => $row->table_title],
                'requested_by' => $row->requested_by,
                // The waiter in charge of the table, when tables have one.
                'waiter' => $row->waiter_id === null ? null : ['id' => (int) $row->waiter_id, 'name' => $row->waiter_name],
                'total' => (float) $row->total,
                'remaining' => max(0.0, (float) $row->total - (float) $row->paid),
            ];
        }

        return $calls;
    }

    /** The restaurant's open calls on tables still open (closing or deleting resolves them too). */
    private static function openQuery(int $restaurantId): Builder
    {
        return DB::table('table_calls')
            ->join('restaurant_tables', 'restaurant_tables.id', '=', 'table_calls.restaurant_table_id')
            ->where('table_calls.user_id', $restaurantId)
            ->whereNull('table_calls.resolved_at')
            ->whereNull('restaurant_tables.closed_at')
            ->whereNull('restaurant_tables.deleted_at');
    }
}
