<?php

namespace App\Support\Admin;

use App\Enums\AccountType;
use App\Enums\OnlinePaymentStatus;
use App\Enums\QueueItemStatus;
use App\Enums\TableCustomerStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The admin panel's figures, aggregated in SQL. People are only ever counted, never
 * listed: the panel shows how the platform is used, not who uses it. Restaurants are
 * businesses on the platform's books and get a full report each (restaurant()).
 *
 * Money is summed in cents and returned in reais. Days are the business timezone's
 * (config app.business_timezone), like the restaurant's own dashboard.
 */
class PlatformStats
{
    /** Days in the charts and in the "last 30 days" figures. */
    public const DAYS = 30;

    private const TOP_RESTAURANTS = 10;

    private const TOP_PRODUCTS = 8;

    private string $tz;

    private CarbonImmutable $now;

    public function __construct()
    {
        $this->tz = (string) config('app.business_timezone');
        $this->now = CarbonImmutable::now();
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        return [
            'users' => $this->users(),
            'restaurants' => $this->restaurantCounts(),
            'activity' => $this->activity(null),
            'timeline' => $this->timeline(null, withSignups: true),
            'payment_methods' => $this->paymentMethods(null),
            'top_restaurants' => $this->topRestaurants(),
        ];
    }

    /** @return array<string, mixed> */
    public function restaurant(int $restaurantId): array
    {
        $since = $this->since(self::DAYS);

        $team = DB::table('users')
            ->where('restaurant_id', $restaurantId)
            ->where('account_type', AccountType::WAITER->value)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(*) FILTER (WHERE last_seen_at >= ?) as active', [$since])
            ->first();

        $tables = DB::table('restaurant_tables')
            ->where('user_id', $restaurantId)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) FILTER (WHERE closed_at IS NULL) as open')
            ->selectRaw('MAX(created_at) as last_opened')
            ->first();

        return [
            'counts' => [
                'team' => (int) ($team->total ?? 0),
                'team_active' => (int) ($team->active ?? 0),
                'products' => DB::table('products')->where('user_id', $restaurantId)->whereNull('deleted_at')->count(),
                'menus' => DB::table('menus')->where('user_id', $restaurantId)->count(),
                'queues' => DB::table('restaurant_queues')->where('user_id', $restaurantId)->count(),
                'open_tables' => (int) ($tables->open ?? 0),
                'last_table_opened_at' => isset($tables->last_opened)
                    ? CarbonImmutable::parse((string) $tables->last_opened, 'UTC')->toIso8601String()
                    : null,
            ],
            'activity' => $this->activity($restaurantId),
            'timeline' => $this->timeline($restaurantId, withSignups: false),
            'payment_methods' => $this->paymentMethods($restaurantId),
            'top_products' => $this->topProducts($restaurantId),
        ];
    }

    /**
     * Accounts by type, sign-ups, sign-ins and activity over the last day, week and month.
     *
     * @return array<string, mixed>
     */
    private function users(): array
    {
        $day = $this->since(1);
        $week = $this->since(7);
        $month = $this->since(self::DAYS);

        $row = DB::table('users')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(*) FILTER (WHERE email_verified_at IS NOT NULL) as verified')
            ->selectRaw("COUNT(*) FILTER (WHERE account_type = 'customer') as customers")
            ->selectRaw("COUNT(*) FILTER (WHERE account_type = 'restaurant') as restaurants")
            ->selectRaw("COUNT(*) FILTER (WHERE account_type = 'waiter') as staff")
            ->selectRaw("COUNT(*) FILTER (WHERE account_type = 'admin') as admins")
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ?) as signups_day', [$day])
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ?) as signups_week', [$week])
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ?) as signups_month', [$month])
            ->selectRaw('COUNT(*) FILTER (WHERE last_login_at >= ?) as logins_day', [$day])
            ->selectRaw('COUNT(*) FILTER (WHERE last_login_at >= ?) as logins_week', [$week])
            ->selectRaw('COUNT(*) FILTER (WHERE last_login_at >= ?) as logins_month', [$month])
            ->selectRaw('COUNT(*) FILTER (WHERE last_seen_at >= ?) as active_day', [$day])
            ->selectRaw('COUNT(*) FILTER (WHERE last_seen_at >= ?) as active_week', [$week])
            ->selectRaw('COUNT(*) FILTER (WHERE last_seen_at >= ?) as active_month', [$month])
            ->selectRaw("COUNT(*) FILTER (WHERE account_type = 'customer' AND last_seen_at >= ?) as customers_active_week", [$week])
            ->first();

        // Customers who sat at a table (joined it) in the last 30 days: people actually using the product.
        $seated = DB::table('restaurant_table_customers')
            ->where('created_at', '>=', $month)
            ->where('status', TableCustomerStatus::APPROVED->value)
            ->distinct()
            ->count('user_id');

        $int = fn (string $key): int => (int) ($row->{$key} ?? 0);

        return [
            'total' => $int('total'),
            'verified' => $int('verified'),
            'by_type' => [
                'customer' => $int('customers'),
                'restaurant' => $int('restaurants'),
                'waiter' => $int('staff'),
                'admin' => $int('admins'),
            ],
            'signups' => ['day' => $int('signups_day'), 'week' => $int('signups_week'), 'month' => $int('signups_month')],
            'logins' => ['day' => $int('logins_day'), 'week' => $int('logins_week'), 'month' => $int('logins_month')],
            'active' => ['day' => $int('active_day'), 'week' => $int('active_week'), 'month' => $int('active_month')],
            'customers_active_week' => $int('customers_active_week'),
            'customers_seated_month' => $seated,
        ];
    }

    /** @return array<string, int> */
    private function restaurantCounts(): array
    {
        $row = DB::table('users')
            ->where('account_type', AccountType::RESTAURANT->value)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(*) FILTER (WHERE stripe_charges_enabled) as stripe_active')
            ->selectRaw('COUNT(*) FILTER (WHERE profile_public) as public_profiles')
            ->selectRaw('COUNT(*) FILTER (WHERE fee_percent_override IS NOT NULL) as custom_fee')
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ?) as new_month', [$this->since(self::DAYS)])
            ->first();

        // Venues that closed at least one bill in the period: the ones really running on the app.
        $operating = fn (int $days): int => DB::table('restaurant_tables')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $this->since($days))
            ->whereNull('deleted_at')
            ->distinct()
            ->count('user_id');

        return [
            'total' => (int) ($row->total ?? 0),
            'stripe_active' => (int) ($row->stripe_active ?? 0),
            'public_profiles' => (int) ($row->public_profiles ?? 0),
            'custom_fee' => (int) ($row->custom_fee ?? 0),
            'new_month' => (int) ($row->new_month ?? 0),
            'operating_week' => $operating(7),
            'operating_month' => $operating(self::DAYS),
        ];
    }

    /**
     * Orders, bills and payments: all time, the last 30 days and the 30 days before
     * (for the change), for the platform or one restaurant.
     *
     * @return array<string, array<string, float|int>>
     */
    private function activity(?int $restaurantId): array
    {
        $month = $this->since(self::DAYS);
        $previous = $this->since(2 * self::DAYS);
        $day = $this->since(1);

        $tables = DB::table('restaurant_tables')
            ->whereNull('deleted_at')
            ->whereNotNull('closed_at')
            ->when($restaurantId !== null, fn (Builder $q) => $q->where('user_id', $restaurantId))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(*) FILTER (WHERE closed_at >= ?) as month', [$month])
            ->selectRaw('COUNT(*) FILTER (WHERE closed_at >= ? AND closed_at < ?) as previous', [$previous, $month])
            ->selectRaw('COUNT(*) FILTER (WHERE closed_at >= ?) as day', [$day])
            ->first();

        // Order lines (what customers and staff ordered), by when they were ordered.
        $items = DB::table('queue_items as qi')
            ->when($restaurantId !== null, fn (Builder $q) => $q
                ->join('restaurant_tables as rt', 'rt.id', '=', 'qi.restaurant_table_id')
                ->where('rt.user_id', $restaurantId))
            ->selectRaw('COALESCE(SUM(qi.quantity), 0) as total')
            ->selectRaw('COALESCE(SUM(qi.quantity) FILTER (WHERE qi.created_at >= ?), 0) as month', [$month])
            ->selectRaw('COALESCE(SUM(qi.quantity) FILTER (WHERE qi.created_at >= ? AND qi.created_at < ?), 0) as previous', [$previous, $month])
            ->selectRaw('COALESCE(SUM(qi.quantity) FILTER (WHERE qi.created_at >= ?), 0) as day', [$day])
            ->selectRaw('COALESCE(SUM(qi.quantity) FILTER (WHERE qi.created_at >= ? AND qi.status = ?), 0) as abandoned_month', [$month, QueueItemStatus::ABANDONED->value])
            ->first();

        // Everything paid on the bills: cash, Pix, card, coupons and in-app payments.
        $payments = DB::table('table_payments as tp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'tp.restaurant_table_id')
            ->whereNull('rt.deleted_at')
            ->when($restaurantId !== null, fn (Builder $q) => $q->where('rt.user_id', $restaurantId))
            ->selectRaw('COUNT(*) as count_total')
            ->selectRaw('COUNT(*) FILTER (WHERE tp.created_at >= ?) as count_month', [$month])
            ->selectRaw('COUNT(*) FILTER (WHERE tp.created_at >= ? AND tp.created_at < ?) as count_previous', [$previous, $month])
            ->selectRaw('COALESCE(SUM(ROUND(tp.amount * 100)), 0) as cents_total')
            ->selectRaw('COALESCE(SUM(ROUND(tp.amount * 100)) FILTER (WHERE tp.created_at >= ?), 0) as cents_month', [$month])
            ->selectRaw('COALESCE(SUM(ROUND(tp.amount * 100)) FILTER (WHERE tp.created_at >= ? AND tp.created_at < ?), 0) as cents_previous', [$previous, $month])
            ->selectRaw('COALESCE(SUM(ROUND(tp.amount * 100)) FILTER (WHERE tp.created_at >= ?), 0) as cents_day', [$day])
            ->first();

        // In-app payments and what the platform kept of them: its commission on the part
        // not refunded to the customer, minus Stripe fees it gave back to sponsored venues.
        $commission = 'GREATEST(0, CASE WHEN charge_cents > 0 THEN ROUND(application_fee_cents::numeric * (charge_cents - refunded_cents) / charge_cents) ELSE 0 END - COALESCE(fee_refund_cents, 0))';
        $online = DB::table('online_payments')
            ->where('status', OnlinePaymentStatus::PAID->value)
            ->when($restaurantId !== null, fn (Builder $q) => $q->where('restaurant_id', $restaurantId))
            ->selectRaw('COUNT(*) as count_total')
            ->selectRaw('COUNT(*) FILTER (WHERE paid_at >= ?) as count_month', [$month])
            ->selectRaw('COALESCE(SUM(charge_cents - refunded_cents), 0) as cents_total')
            ->selectRaw('COALESCE(SUM(charge_cents - refunded_cents) FILTER (WHERE paid_at >= ?), 0) as cents_month', [$month])
            ->selectRaw("COALESCE(SUM({$commission}), 0) as fee_total")
            ->selectRaw("COALESCE(SUM({$commission}) FILTER (WHERE paid_at >= ?), 0) as fee_month", [$month])
            ->selectRaw("COALESCE(SUM({$commission}) FILTER (WHERE paid_at >= ? AND paid_at < ?), 0) as fee_previous", [$previous, $month])
            ->selectRaw('COALESCE(SUM(discount_cents) FILTER (WHERE coupon_by_platform), 0) as platform_coupons_total')
            ->first();

        $int = fn (?object $row, string $key): int => (int) ($row->{$key} ?? 0);
        $reais = fn (?object $row, string $key): float => ((int) ($row->{$key} ?? 0)) / 100;

        $tablesMonth = $int($tables, 'month');
        $tablesPrevious = $int($tables, 'previous');

        return [
            'tables' => [
                'total' => $int($tables, 'total'),
                'month' => $tablesMonth,
                'previous' => $tablesPrevious,
                'day' => $int($tables, 'day'),
            ],
            'items' => [
                'total' => $int($items, 'total'),
                'month' => $int($items, 'month'),
                'previous' => $int($items, 'previous'),
                'day' => $int($items, 'day'),
                'abandoned_month' => $int($items, 'abandoned_month'),
            ],
            'payments' => [
                'count_total' => $int($payments, 'count_total'),
                'count_month' => $int($payments, 'count_month'),
                'count_previous' => $int($payments, 'count_previous'),
                'total' => $reais($payments, 'cents_total'),
                'month' => $reais($payments, 'cents_month'),
                'previous' => $reais($payments, 'cents_previous'),
                'day' => $reais($payments, 'cents_day'),
                // Average paid per closed bill in the period.
                'avg_ticket_month' => $tablesMonth > 0 ? round($int($payments, 'cents_month') / $tablesMonth) / 100 : 0,
                'avg_ticket_previous' => $tablesPrevious > 0 ? round($int($payments, 'cents_previous') / $tablesPrevious) / 100 : 0,
            ],
            'online' => [
                'count_total' => $int($online, 'count_total'),
                'count_month' => $int($online, 'count_month'),
                'total' => $reais($online, 'cents_total'),
                'month' => $reais($online, 'cents_month'),
                'fee_total' => $reais($online, 'fee_total'),
                'fee_month' => $reais($online, 'fee_month'),
                'fee_previous' => $reais($online, 'fee_previous'),
                'platform_coupons_total' => $reais($online, 'platform_coupons_total'),
            ],
        ];
    }

    /**
     * One point per local day of the last 30: amount paid, bills closed, items ordered
     * (and, platform-wide, new accounts).
     *
     * @return list<array<string, float|int|string>>
     */
    private function timeline(?int $restaurantId, bool $withSignups): array
    {
        $start = $this->localDayStart(self::DAYS - 1);
        $paid = DB::table('table_payments as tp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'tp.restaurant_table_id')
            ->whereNull('rt.deleted_at')
            ->where('tp.created_at', '>=', $start)
            ->when($restaurantId !== null, fn (Builder $q) => $q->where('rt.user_id', $restaurantId))
            ->selectRaw("TO_CHAR((tp.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') as day, SUM(ROUND(tp.amount * 100)) as cents", [$this->tz])
            ->groupBy('day')
            ->pluck('cents', 'day');

        $tables = DB::table('restaurant_tables')
            ->whereNull('deleted_at')
            ->where('closed_at', '>=', $start)
            ->when($restaurantId !== null, fn (Builder $q) => $q->where('user_id', $restaurantId))
            ->selectRaw("TO_CHAR((closed_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') as day, COUNT(*) as total", [$this->tz])
            ->groupBy('day')
            ->pluck('total', 'day');

        $items = DB::table('queue_items as qi')
            ->where('qi.created_at', '>=', $start)
            ->when($restaurantId !== null, fn (Builder $q) => $q
                ->join('restaurant_tables as rt', 'rt.id', '=', 'qi.restaurant_table_id')
                ->where('rt.user_id', $restaurantId))
            ->selectRaw("TO_CHAR((qi.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') as day, SUM(qi.quantity) as total", [$this->tz])
            ->groupBy('day')
            ->pluck('total', 'day');

        $signups = $withSignups
            ? DB::table('users')
                ->where('created_at', '>=', $start)
                ->selectRaw("TO_CHAR((created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') as day, COUNT(*) as total", [$this->tz])
                ->groupBy('day')
                ->pluck('total', 'day')
            : collect();

        $points = [];
        $cursor = $this->now->setTimezone($this->tz)->startOfDay()->subDays(self::DAYS - 1);

        for ($i = 0; $i < self::DAYS; $i++) {
            $key = $cursor->addDays($i)->toDateString();
            $point = [
                'day' => $key,
                'revenue' => ((int) ($paid[$key] ?? 0)) / 100,
                'tables' => (int) ($tables[$key] ?? 0),
                'items' => (int) ($items[$key] ?? 0),
            ];
            if ($withSignups) {
                $point['signups'] = (int) ($signups[$key] ?? 0);
            }
            $points[] = $point;
        }

        return $points;
    }

    /** @return list<array{method: string, count: int, total: int|float}> */
    private function paymentMethods(?int $restaurantId): array
    {
        return array_values(DB::table('table_payments as tp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'tp.restaurant_table_id')
            ->whereNull('rt.deleted_at')
            ->where('tp.created_at', '>=', $this->since(self::DAYS))
            ->when($restaurantId !== null, fn (Builder $q) => $q->where('rt.user_id', $restaurantId))
            ->selectRaw('tp.method, COUNT(*) as count, SUM(ROUND(tp.amount * 100)) as cents')
            ->groupBy('tp.method')
            ->orderByDesc('cents')
            ->get()
            ->map(fn (object $row) => [
                'method' => (string) $row->method,
                'count' => (int) $row->count,
                'total' => ((int) $row->cents) / 100,
            ])
            ->all());
    }

    /**
     * The restaurants that took the most in the last 30 days.
     *
     * @return list<array{id: int, name: string, revenue: int|float, tables: int}>
     */
    private function topRestaurants(): array
    {
        $since = $this->since(self::DAYS);

        return array_values(DB::table('table_payments as tp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'tp.restaurant_table_id')
            ->join('users as u', 'u.id', '=', 'rt.user_id')
            ->whereNull('rt.deleted_at')
            ->where('tp.created_at', '>=', $since)
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.id, u.name, SUM(ROUND(tp.amount * 100)) as cents, COUNT(DISTINCT rt.id) as tables')
            ->orderByDesc('cents')
            ->limit(self::TOP_RESTAURANTS)
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'revenue' => ((int) $row->cents) / 100,
                'tables' => (int) $row->tables,
            ])
            ->all());
    }

    /**
     * A restaurant's best sellers over the bills closed in the last 30 days.
     *
     * @return list<array{id: int, name: string, quantity: int, revenue: int|float}>
     */
    private function topProducts(int $restaurantId): array
    {
        return array_values(DB::table('restaurant_table_product as rtp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'rtp.restaurant_table_id')
            ->join('products as p', 'p.id', '=', 'rtp.product_id')
            ->where('rt.user_id', $restaurantId)
            ->whereNull('rt.deleted_at')
            ->where('rt.closed_at', '>=', $this->since(self::DAYS))
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.id, p.name, SUM(rtp.quantity) as quantity, SUM(ROUND(rtp.price * rtp.quantity * 100)) as cents')
            ->orderByDesc('cents')
            ->limit(self::TOP_PRODUCTS)
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'quantity' => (int) $row->quantity,
                'revenue' => ((int) $row->cents) / 100,
            ])
            ->all());
    }

    /** A rolling window back from now, as a UTC timestamp string for the queries. */
    private function since(int $days): string
    {
        return $this->now->utc()->subDays($days)->toDateTimeString();
    }

    /** Start (in UTC) of the local day $daysAgo days before today. */
    private function localDayStart(int $daysAgo): string
    {
        return $this->now->setTimezone($this->tz)->startOfDay()->subDays($daysAgo)->utc()->toDateTimeString();
    }
}
