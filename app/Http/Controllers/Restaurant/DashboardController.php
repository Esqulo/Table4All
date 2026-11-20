<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Support\Dashboard\DashboardRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's sales dashboard. Everything is aggregated in SQL over the bill
 * lines (restaurant_table_product) of the tables closed in the period, using
 * the prices snapshotted at order time. Days and hours are the restaurant's
 * local ones (config app.business_timezone): a table closed at 22:00 in
 * Brasilia is 01:00 UTC of the next day and must count on its own day.
 *
 * The optional category filter narrows the product figures (revenue, items,
 * charts, rankings) to that category's lines; a table counts when it had at
 * least one of them. Payment methods and the recent tables list stay whole,
 * since a payment covers the full bill.
 */
class DashboardController extends Controller
{
    private const TOP_PRODUCTS = 8;

    public function index(Request $request): Response
    {
        $restaurantId = $request->user()->effectiveRestaurantId();
        $tz = (string) config('app.business_timezone');
        $range = DashboardRange::fromRequest($request, $tz);
        $previous = $range->previous();

        $categories = DB::table('categories as c')
            ->whereExists(fn (Builder $q) => $q->from('products as p')
                ->whereColumn('p.category_id', 'c.id')
                ->where('p.user_id', $restaurantId))
            ->orderBy('c.name')
            ->get(['c.id', 'c.name']);

        $categoryId = $request->integer('category') ?: null;
        if ($categoryId !== null && ! $categories->contains('id', $categoryId)) {
            $categoryId = null;
        }

        $summary = $this->summary($restaurantId, $range, $categoryId);
        $previousSummary = $this->summary($restaurantId, $previous, $categoryId);

        return Inertia::render('restaurant/dashboards', [
            'filters' => [
                'period' => $range->period,
                'from' => $range->from->toDateString(),
                'to' => $range->to->toDateString(),
                'previous_from' => $previous->from->toDateString(),
                'previous_to' => $previous->to->toDateString(),
                'today' => now($tz)->toDateString(),
                'max_days' => DashboardRange::MAX_DAYS,
                'granularity' => $range->hourly() ? 'hour' : 'day',
                'category' => $categoryId,
            ],
            'categories' => $categories,
            'summary' => $summary,
            'previous_summary' => $previousSummary,
            'timeline' => $this->timeline($restaurantId, $range, $categoryId),
            'heatmap' => $range->hourly() ? [] : $this->heatmap($restaurantId, $range, $categoryId),
            'top_products' => $this->topProducts($restaurantId, $range, $categoryId, 'revenue'),
            'top_products_by_qty' => $this->topProducts($restaurantId, $range, $categoryId, 'qty'),
            'revenue_by_category' => $categoryId === null ? $this->revenueByCategory($restaurantId, $range) : [],
            'payment_methods' => $this->paymentMethods($restaurantId, $range),
            'recent_tables' => $this->recentTables($restaurantId, $range),
        ]);
    }

    /**
     * Bill lines of this restaurant's tables closed in the range (optionally of one category).
     */
    private function lines(int $restaurantId, DashboardRange $range, ?int $categoryId): Builder
    {
        return DB::table('restaurant_table_product as rtp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'rtp.restaurant_table_id')
            ->where('rt.user_id', $restaurantId)
            ->where('rt.closed_at', '>=', $range->startUtc())
            ->where('rt.closed_at', '<', $range->endUtc())
            ->whereNull('rt.deleted_at')
            ->when($categoryId !== null, fn (Builder $q) => $q
                ->join('products as p', 'p.id', '=', 'rtp.product_id')
                ->where('p.category_id', $categoryId));
    }

    /**
     * @return array<string, int|float>
     */
    private function summary(int $restaurantId, DashboardRange $range, ?int $categoryId): array
    {
        // ordered_by_user_id is set for what customers ordered themselves by QR;
        // staff-entered lines are unattributed (see TableController::update).
        $totals = $this->lines($restaurantId, $range, $categoryId)
            ->selectRaw('
                COALESCE(SUM(rtp.price * rtp.quantity), 0)                                                as total_revenue,
                COUNT(DISTINCT rt.id)                                                                     as tables_closed,
                COALESCE(SUM(rtp.quantity), 0)                                                            as items_sold,
                COALESCE(SUM(rtp.price * rtp.quantity) FILTER (WHERE rtp.ordered_by_user_id IS NOT NULL), 0) as qr_revenue
            ')
            ->first();

        // Duration is per table, so it's averaged over the distinct tables, not the lines.
        $duration = DB::query()
            ->fromSub(
                $this->lines($restaurantId, $range, $categoryId)
                    ->select('rt.id', 'rt.created_at', 'rt.closed_at')
                    ->distinct(),
                't',
            )
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (t.closed_at - t.created_at))) / 60 as minutes')
            ->value('minutes');

        // Orders the customer left without (table closed before delivery): not billed,
        // but food/drink the restaurant prepared or owed. See TableController::close.
        $abandoned = DB::table('queue_items as qi')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'qi.restaurant_table_id')
            ->where('rt.user_id', $restaurantId)
            ->where('rt.closed_at', '>=', $range->startUtc())
            ->where('rt.closed_at', '<', $range->endUtc())
            ->whereNull('rt.deleted_at')
            ->where('qi.status', QueueItemStatus::ABANDONED->value)
            ->when($categoryId !== null, fn (Builder $q) => $q
                ->join('products as p', 'p.id', '=', 'qi.product_id')
                ->where('p.category_id', $categoryId))
            ->selectRaw('COALESCE(SUM(qi.quantity), 0) as items, COALESCE(SUM(qi.price * qi.quantity), 0) as value')
            ->first();

        $revenue = (float) ($totals->total_revenue ?? 0);
        $tables = (int) ($totals->tables_closed ?? 0);
        $items = (int) ($totals->items_sold ?? 0);

        return [
            'total_revenue' => $revenue,
            'tables_closed' => $tables,
            'items_sold' => $items,
            'avg_table_revenue' => $tables > 0 ? round($revenue / $tables, 2) : 0.0,
            'avg_items_per_table' => $tables > 0 ? round($items / $tables, 1) : 0.0,
            'avg_duration_minutes' => (int) round((float) $duration),
            'qr_revenue' => (float) ($totals->qr_revenue ?? 0),
            'abandoned_items' => (int) ($abandoned->items ?? 0),
            'abandoned_value' => (float) ($abandoned->value ?? 0),
        ];
    }

    /**
     * Revenue per local day (or per hour for a single day), with every bucket
     * present (zeros included) and the matching bucket of the previous period.
     *
     * @return list<array{bucket: string, revenue: float, tables_count: int, previous_revenue: float}>
     */
    private function timeline(int $restaurantId, DashboardRange $range, ?int $categoryId): array
    {
        $current = $this->bucketed($restaurantId, $range, $categoryId);
        $previous = $this->bucketed($restaurantId, $range->previous(), $categoryId);
        $points = [];

        if ($range->hourly()) {
            for ($hour = 0; $hour < 24; $hour++) {
                $key = sprintf('%02d', $hour);
                $points[] = [
                    'bucket' => $key,
                    'revenue' => $current[$key]['revenue'] ?? 0.0,
                    'tables_count' => $current[$key]['tables'] ?? 0,
                    'previous_revenue' => $previous[$key]['revenue'] ?? 0.0,
                ];
            }

            return $points;
        }

        $previousFrom = $range->previous()->from;
        for ($i = 0; $i < $range->days(); $i++) {
            $key = $range->from->addDays($i)->toDateString();
            $previousKey = $previousFrom->addDays($i)->toDateString();
            $points[] = [
                'bucket' => $key,
                'revenue' => $current[$key]['revenue'] ?? 0.0,
                'tables_count' => $current[$key]['tables'] ?? 0,
                'previous_revenue' => $previous[$previousKey]['revenue'] ?? 0.0,
            ];
        }

        return $points;
    }

    /**
     * @return array<string, array{revenue: float, tables: int}>
     */
    private function bucketed(int $restaurantId, DashboardRange $range, ?int $categoryId): array
    {
        $bucket = $range->hourly()
            ? "TO_CHAR((rt.closed_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'HH24')"
            : "TO_CHAR((rt.closed_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD')";

        return $this->lines($restaurantId, $range, $categoryId)
            ->selectRaw($bucket.' as bucket, SUM(rtp.price * rtp.quantity) as revenue, COUNT(DISTINCT rt.id) as tables', [$range->timezone])
            // By position: PostgreSQL can't match the bound timezone of the SELECT to a second placeholder.
            ->groupByRaw('1')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                (string) $row->bucket => ['revenue' => (float) $row->revenue, 'tables' => (int) $row->tables],
            ])
            ->all();
    }

    /**
     * When customers order: items ordered per local weekday (ISO, 1 = Monday)
     * and hour, from the order tickets of the tables closed in the range.
     *
     * @return array<int, array{weekday: int, hour: int, items: int, revenue: float}>
     */
    private function heatmap(int $restaurantId, DashboardRange $range, ?int $categoryId): array
    {
        $local = "((qi.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?)";

        return DB::table('queue_items as qi')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'qi.restaurant_table_id')
            ->where('rt.user_id', $restaurantId)
            ->where('rt.closed_at', '>=', $range->startUtc())
            ->where('rt.closed_at', '<', $range->endUtc())
            ->whereNull('rt.deleted_at')
            ->when($categoryId !== null, fn (Builder $q) => $q
                ->join('products as p', 'p.id', '=', 'qi.product_id')
                ->where('p.category_id', $categoryId))
            ->selectRaw(
                "EXTRACT(ISODOW FROM {$local})::int as weekday, EXTRACT(HOUR FROM {$local})::int as hour,
                 SUM(qi.quantity) as items, SUM(qi.price * qi.quantity) as revenue",
                [$range->timezone, $range->timezone],
            )
            ->groupByRaw('1, 2')
            ->get()
            ->map(fn (object $row) => [
                'weekday' => (int) $row->weekday,
                'hour' => (int) $row->hour,
                'items' => (int) $row->items,
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  'revenue'|'qty'  $by
     * @return array<int, array{id: int, name: string, qty: int, revenue: float}>
     */
    private function topProducts(int $restaurantId, DashboardRange $range, ?int $categoryId, string $by): array
    {
        return $this->lines($restaurantId, $range, $categoryId)
            ->join('products as tp', 'tp.id', '=', 'rtp.product_id')
            ->selectRaw('tp.id, tp.name, SUM(rtp.quantity) as qty, SUM(rtp.price * rtp.quantity) as revenue')
            ->groupBy('tp.id', 'tp.name')
            ->orderByDesc($by)
            ->orderBy('tp.name')
            ->limit(self::TOP_PRODUCTS)
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'qty' => (int) $row->qty,
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int|null, name: string|null, qty: int, revenue: float}>
     */
    private function revenueByCategory(int $restaurantId, DashboardRange $range): array
    {
        return $this->lines($restaurantId, $range, null)
            ->join('products as cp', 'cp.id', '=', 'rtp.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'cp.category_id')
            ->selectRaw('c.id, c.name, SUM(rtp.quantity) as qty, SUM(rtp.price * rtp.quantity) as revenue')
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn (object $row) => [
                'id' => $row->id === null ? null : (int) $row->id,
                'name' => $row->name === null ? null : (string) $row->name,
                'qty' => (int) $row->qty,
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->all();
    }

    /**
     * Payments of the tables closed in the range (the same tables as the revenue).
     *
     * @return array<int, array{method: string, count: int, total: float}>
     */
    private function paymentMethods(int $restaurantId, DashboardRange $range): array
    {
        return DB::table('table_payments as tp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'tp.restaurant_table_id')
            ->where('rt.user_id', $restaurantId)
            ->where('rt.closed_at', '>=', $range->startUtc())
            ->where('rt.closed_at', '<', $range->endUtc())
            ->whereNull('rt.deleted_at')
            ->selectRaw('tp.method, COUNT(*) as count, SUM(tp.amount) as total')
            ->groupBy('tp.method')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row) => [
                'method' => (string) $row->method,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, title: string, closed_at: string, created_at: string, total: float, items: int}>
     */
    private function recentTables(int $restaurantId, DashboardRange $range): array
    {
        return DB::table('restaurant_tables as rt')
            ->leftJoin('restaurant_table_product as rtp', 'rtp.restaurant_table_id', '=', 'rt.id')
            ->where('rt.user_id', $restaurantId)
            ->where('rt.closed_at', '>=', $range->startUtc())
            ->where('rt.closed_at', '<', $range->endUtc())
            ->whereNull('rt.deleted_at')
            ->selectRaw('rt.id, rt.title, rt.closed_at, rt.created_at, COALESCE(SUM(rtp.price * rtp.quantity), 0) as total, COALESCE(SUM(rtp.quantity), 0) as items')
            ->groupBy('rt.id', 'rt.title', 'rt.closed_at', 'rt.created_at')
            ->orderByDesc('rt.closed_at')
            ->limit(15)
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                // Stored in UTC without a zone: say so, or the browser reads it as local time.
                'closed_at' => CarbonImmutable::parse((string) $row->closed_at, 'UTC')->toIso8601ZuluString(),
                'created_at' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->toIso8601ZuluString(),
                'total' => (float) $row->total,
                'items' => (int) $row->items,
            ])
            ->values()
            ->all();
    }
}
