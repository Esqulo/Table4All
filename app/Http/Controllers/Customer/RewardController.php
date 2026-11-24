<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OnlinePaymentStatus;
use App\Enums\TableCustomerStatus;
use App\Http\Controllers\Controller;
use App\Support\Loyalty\LoyaltyProgram;
use App\Support\ServiceFee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's own page: level and achievements, a loyalty card per place
 * they've been to lately (with the rewards it gives), their reward coupons and
 * the history of their tables and payments.
 */
class RewardController extends Controller
{
    private const HISTORY_PER_PAGE = 10;

    /** XP needed for level n is LEVEL_STEP × (n - 1)². */
    private const LEVEL_STEP = 200;

    /** XP for each visit (on top of 1 XP per R$ ordered). */
    private const XP_PER_VISIT = 100;

    /** Streak that unlocks the "loyal customer" achievement. */
    private const LOYAL_STREAK = 3;

    public function index(Request $request, LoyaltyProgram $loyalty): Response
    {
        $userId = $request->user()->id;

        $visitDays = $loyalty->visitDays($userId);
        // Hands out the rewards reached since last time, so they show up below.
        $loyalty->sync($userId, $visitDays);

        $visits = array_sum(array_map('count', $visitDays));
        $ordered = (float) DB::table('restaurant_table_product as rtp')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'rtp.restaurant_table_id')
            ->where('rtp.ordered_by_user_id', $userId)
            ->whereNotNull('rt.closed_at')
            ->whereNull('rt.deleted_at')
            ->sum(DB::raw('rtp.price * rtp.quantity'));

        $payments = DB::table('online_payments')
            ->where('user_id', $userId)
            ->where('status', OnlinePaymentStatus::PAID->value)
            ->selectRaw('
                COALESCE(SUM(charge_cents - refunded_cents), 0) as paid_cents,
                COALESCE(SUM(discount_cents), 0)              as saved_cents,
                COUNT(coupon_id)                              as coupons_used
            ')
            ->first();

        $stats = [
            'visits' => $visits,
            'places' => count($visitDays),
            'best_streak' => $loyalty->bestStreak($visitDays),
            'ordered' => round($ordered, 2),
            'paid_in_app' => ((int) ($payments->paid_cents ?? 0)) / 100,
            'saved' => ((int) ($payments->saved_cents ?? 0)) / 100,
            'coupons_used' => (int) ($payments->coupons_used ?? 0),
        ];

        return Inertia::render('customer/rewards', [
            'level' => $this->level($visits * self::XP_PER_VISIT + (int) floor($ordered)),
            'stats' => $stats,
            'achievements' => $this->achievements($stats),
            'loyalty' => $loyalty->cards($userId, $visitDays),
            'loyalty_rules' => [
                'streak_days' => (int) config('incentives.loyalty.streak_days'),
            ],
            'wallet' => $this->wallet($userId),
            'history' => $this->history($userId),
        ]);
    }

    /**
     * @return array{level: int, xp: int, level_xp: int, next_level_xp: int}
     */
    private function level(int $xp): array
    {
        $level = (int) floor(sqrt($xp / self::LEVEL_STEP)) + 1;

        return [
            'level' => $level,
            'xp' => $xp,
            'level_xp' => self::LEVEL_STEP * ($level - 1) ** 2,
            'next_level_xp' => self::LEVEL_STEP * $level ** 2,
        ];
    }

    /**
     * @param  array{visits: int, places: int, best_streak: int, ordered: float, coupons_used: int}  $stats
     * @return list<array{key: string, progress: int, goal: int, unlocked: bool}>
     */
    private function achievements(array $stats): array
    {
        $goals = [
            'first_visit' => [$stats['visits'], 1],
            'regular' => [$stats['visits'], 10],
            'explorer' => [$stats['places'], 5],
            'loyal' => [$stats['best_streak'], self::LOYAL_STREAK],
            'saver' => [$stats['coupons_used'], 1],
            'big_spender' => [(int) floor($stats['ordered']), 500],
        ];

        $achievements = [];
        foreach ($goals as $key => [$progress, $goal]) {
            $achievements[] = [
                'key' => $key,
                'progress' => min($progress, $goal),
                'goal' => $goal,
                'unlocked' => $progress >= $goal,
            ];
        }

        return $achievements;
    }

    /**
     * The customer's personal coupons (loyalty rewards), usable ones first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function wallet(int $userId): array
    {
        return DB::table('coupons as c')
            // No restaurant for a platform coupon made for this customer: valid everywhere.
            ->leftJoin('users as r', 'r.id', '=', 'c.user_id')
            ->where('c.customer_id', $userId)
            ->whereNull('c.deleted_at')
            ->selectRaw('c.id, c.code, c.discount_type, c.discount_value, c.max_discount, c.expires_at, c.max_uses, r.name as restaurant_name, r.handle as restaurant_handle')
            ->selectSub(
                DB::table('online_payments')
                    ->whereColumn('coupon_id', 'c.id')
                    ->where('status', OnlinePaymentStatus::PAID->value)
                    ->selectRaw('COUNT(*)'),
                'uses',
            )
            ->orderByDesc('c.id')
            ->limit(20)
            ->get()
            ->map(function (object $c) {
                $expiresAt = $c->expires_at === null ? null : CarbonImmutable::parse((string) $c->expires_at, 'UTC');
                // Used up once every allowed use is paid; an unlimited one never is.
                $usedUp = $c->max_uses !== null && (int) $c->uses >= (int) $c->max_uses;
                $status = $usedUp ? 'used' : ($expiresAt?->isPast() ? 'expired' : 'available');

                return [
                    'id' => (int) $c->id,
                    'code' => (string) $c->code,
                    'discount_type' => (string) $c->discount_type,
                    'value' => (float) $c->discount_value,
                    'max_discount' => $c->max_discount === null ? null : (float) $c->max_discount,
                    'expires_at' => $expiresAt?->toIso8601ZuluString(),
                    'status' => $status,
                    'restaurant' => [
                        'name' => $c->restaurant_name === null ? (string) config('app.name') : (string) $c->restaurant_name,
                        'handle' => $c->restaurant_handle,
                    ],
                ];
            })
            ->sortBy(fn (array $c) => $c['status'] === 'available' ? 0 : 1)
            ->values()
            ->all();
    }

    /**
     * Tables the customer sat at, newest first, with what they ordered and paid.
     *
     * @return array<string, mixed>
     */
    private function history(int $userId): array
    {
        $page = DB::table('restaurant_table_customers as rtc')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'rtc.restaurant_table_id')
            ->join('users as r', 'r.id', '=', 'rt.user_id')
            ->where('rtc.user_id', $userId)
            ->where('rtc.status', TableCustomerStatus::APPROVED->value)
            ->whereNull('rt.deleted_at')
            ->select([
                'rt.id', 'rt.title', 'rt.closed_at', 'rt.access_code',
                'rt.service_fee_type', 'rt.service_fee_value', 'rt.service_fee_cap', 'rt.service_fee_waived',
                'rtc.created_at as joined_at',
                'r.name as restaurant_name', 'r.handle as restaurant_handle',
            ])
            ->selectSub(
                DB::table('restaurant_table_product')
                    ->whereColumn('restaurant_table_id', 'rt.id')
                    ->selectRaw('COALESCE(SUM(price * quantity), 0)'),
                'table_total',
            )
            ->selectSub(
                DB::table('restaurant_table_product')
                    ->whereColumn('restaurant_table_id', 'rt.id')
                    ->where('ordered_by_user_id', $userId)
                    ->selectRaw('COALESCE(SUM(price * quantity), 0)'),
                'my_total',
            )
            ->orderByDesc('rtc.created_at')
            ->orderByDesc('rt.id')
            ->paginate(self::HISTORY_PER_PAGE, pageName: 'pagina')
            ->withQueryString();

        /** @var list<\stdClass> $rows */
        $rows = $page->items();
        $tableIds = array_map(fn (\stdClass $t) => (int) $t->id, $rows);

        $payments = DB::table('online_payments')
            ->where('user_id', $userId)
            ->whereIn('restaurant_table_id', $tableIds)
            ->whereIn('status', [
                OnlinePaymentStatus::PAID->value,
                OnlinePaymentStatus::PROCESSING->value,
                OnlinePaymentStatus::REFUNDED->value,
            ])
            ->orderBy('id')
            ->get(['restaurant_table_id', 'status', 'amount_cents', 'charge_cents', 'discount_cents', 'refunded_cents', 'paid_at', 'created_at'])
            ->groupBy('restaurant_table_id');

        $utc = fn (mixed $at) => $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC')->toIso8601ZuluString();

        return [
            'data' => array_map(fn (\stdClass $t) => [
                'id' => (int) $t->id,
                'title' => (string) $t->title,
                'restaurant' => ['name' => (string) $t->restaurant_name, 'handle' => $t->restaurant_handle],
                'joined_at' => $utc($t->joined_at),
                'closed_at' => $utc($t->closed_at),
                // Only an open table can be gone back to.
                'code' => $t->closed_at === null ? $t->access_code : null,
                // The bill: the products plus the service fee.
                'table_total' => (float) $t->table_total + ServiceFee::forTable($t, (int) round(100 * (float) $t->table_total)) / 100,
                'my_total' => (float) $t->my_total,
                'payments' => ($payments->get($t->id) ?? collect())->map(fn (object $p) => [
                    'status' => (string) $p->status,
                    'amount' => ((int) $p->amount_cents) / 100,
                    'charged' => ((int) $p->charge_cents - (int) $p->refunded_cents) / 100,
                    'discount' => ((int) $p->discount_cents) / 100,
                    'paid_at' => $utc($p->paid_at ?? $p->created_at),
                ])->values()->all(),
            ], $rows),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'prev_page_url' => $page->previousPageUrl(),
            'next_page_url' => $page->nextPageUrl(),
        ];
    }
}
