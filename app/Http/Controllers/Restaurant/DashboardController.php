<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $period = (int) $request->get('period', 30);
        $period = in_array($period, [7, 30, 90]) ? $period : 30;
        // Days are the restaurant's local days: a table closed at 22:00 in
        // Brasilia is 01:00 UTC of the next day and must count on its own day.
        $tz = (string) config('app.business_timezone');
        $from = now($tz)->subDays($period)->startOfDay()->utc();
        [$closedDay, $closedDayBindings] = $this->localClosedDate($tz);

        $summary = DB::table('restaurant_table_product as rtp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'rtp.restaurant_table_id')
            ->where('rt.user_id', $userId)
            ->whereNotNull('rt.closed_at')
            ->where('rt.closed_at', '>=', $from)
            ->whereNull('rt.deleted_at')
            ->selectRaw('
                COALESCE(SUM(rtp.price * rtp.quantity), 0) as total_revenue,
                COUNT(DISTINCT rt.id)                       as tables_closed,
                COALESCE(SUM(rtp.quantity), 0)              as items_sold
            ')
            ->first();

        $avgRevenue = $summary->tables_closed > 0
            ? round($summary->total_revenue / $summary->tables_closed, 2)
            : 0.0;

        $revenueByDay = DB::table('restaurant_table_product as rtp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'rtp.restaurant_table_id')
            ->where('rt.user_id', $userId)
            ->whereNotNull('rt.closed_at')
            ->where('rt.closed_at', '>=', $from)
            ->whereNull('rt.deleted_at')
            ->selectRaw($closedDay.' as date, SUM(rtp.price * rtp.quantity) as revenue, COUNT(DISTINCT rt.id) as tables_count', $closedDayBindings)
            // By position: PostgreSQL can't match the bound timezone of the SELECT to a second placeholder.
            ->groupByRaw('1')
            ->orderBy('date')
            ->get();

        $topProducts = DB::table('restaurant_table_product as rtp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'rtp.restaurant_table_id')
            ->join('products as p', 'p.id', '=', 'rtp.product_id')
            ->where('rt.user_id', $userId)
            ->whereNotNull('rt.closed_at')
            ->where('rt.closed_at', '>=', $from)
            ->whereNull('rt.deleted_at')
            ->selectRaw('p.name, SUM(rtp.quantity) as qty, SUM(rtp.price * rtp.quantity) as revenue')
            ->groupBy('p.id', 'p.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $paymentMethods = DB::table('table_payments as tp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'tp.restaurant_table_id')
            ->where('rt.user_id', $userId)
            ->where('tp.created_at', '>=', $from)
            ->whereNull('rt.deleted_at')
            ->selectRaw('tp.method, COUNT(*) as count, SUM(tp.amount) as total')
            ->groupBy('tp.method')
            ->orderByDesc('total')
            ->get();

        $recentTables = DB::table('restaurant_tables as rt')
            ->leftJoin('restaurant_table_product as rtp', 'rtp.restaurant_table_id', '=', 'rt.id')
            ->where('rt.user_id', $userId)
            ->whereNotNull('rt.closed_at')
            ->where('rt.closed_at', '>=', $from)
            ->whereNull('rt.deleted_at')
            ->selectRaw('rt.id, rt.title, rt.closed_at, rt.created_at, COALESCE(SUM(rtp.price * rtp.quantity), 0) as total, COALESCE(SUM(rtp.quantity), 0) as items')
            ->groupBy('rt.id', 'rt.title', 'rt.closed_at', 'rt.created_at')
            ->orderByDesc('rt.closed_at')
            ->limit(15)
            ->get();

        // Orders the customer left without (table closed before delivery): not billed,
        // but food/drink the restaurant prepared or owed. See TableController::close.
        $abandoned = DB::table('queue_items as qi')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'qi.restaurant_table_id')
            ->where('rt.user_id', $userId)
            ->whereNotNull('rt.closed_at')
            ->where('rt.closed_at', '>=', $from)
            ->whereNull('rt.deleted_at')
            ->where('qi.status', QueueItemStatus::ABANDONED->value)
            ->selectRaw('COALESCE(SUM(qi.quantity), 0) as items, COALESCE(SUM(qi.price * qi.quantity), 0) as value')
            ->first();

        return Inertia::render('restaurant/dashboards', [
            'period' => $period,
            'summary' => [
                'total_revenue' => (float) $summary->total_revenue,
                'tables_closed' => (int) $summary->tables_closed,
                'items_sold' => (int) $summary->items_sold,
                'avg_table_revenue' => $avgRevenue,
                'abandoned_items' => (int) ($abandoned->items ?? 0),
                'abandoned_value' => (float) ($abandoned->value ?? 0),
            ],
            'revenue_by_day' => $revenueByDay,
            'top_products' => $topProducts,
            'payment_methods' => $paymentMethods,
            'recent_tables' => $recentTables,
        ]);
    }

    /**
     * SQL (with bindings) for the local calendar date of rt.closed_at, stored in UTC.
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private function localClosedDate(string $tz): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return ["DATE((rt.closed_at AT TIME ZONE 'UTC') AT TIME ZONE ?)", [$tz]];
        }

        // SQLite (tests): the zone's current offset (Brazil has no DST).
        $offsetMinutes = now($tz)->utcOffset();

        return ['DATE(rt.closed_at, ?)', [sprintf('%+d minutes', $offsetMinutes)]];
    }
}
