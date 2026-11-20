<?php

namespace App\Support\Loyalty;

use App\Enums\CouponDiscountType;
use App\Enums\Incentive;
use App\Enums\TableCustomerStatus;
use App\Models\Coupon;
use App\Models\User;
use App\Support\Incentives;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The customer's loyalty card at each place.
 *
 * A visit is a local day (business timezone) on which the customer sat at one
 * of the restaurant's tables, approved, and that table was later closed with
 * something on the bill: several tables the same day count once, and joining
 * an empty table earns nothing. Visits no more than `streak_days` apart form a
 * streak; a longer gap (or staying away that long until today) loses it.
 *
 * Each restaurant has a ladder of tiers (e.g. 3 visits → R$ 5, 10 → R$ 15,
 * 30 → R$ 100). Visits made while it has the loyalty incentive on climb the
 * ladder; reaching a tier earns a personal coupon with that tier's discount,
 * paid by the restaurant. After the last tier the ladder starts over. Rewards
 * are keyed by the day of the visit that reached the tier, so handing them
 * out (sync) is safe to repeat and to race.
 *
 * @phpstan-type Tier array{visits: int, discount_type: string, discount_value: float, max_discount: float|null}
 */
final class LoyaltyProgram
{
    private readonly string $timezone;

    private readonly int $streakDays;

    public function __construct()
    {
        $this->timezone = (string) config('app.business_timezone');
        $this->streakDays = (int) config('incentives.loyalty.streak_days');
    }

    /**
     * Local visit days (Y-m-d, ascending) of the customer, per restaurant id.
     *
     * @return array<int, list<string>>
     */
    public function visitDays(int $userId): array
    {
        $day = "TO_CHAR((rtc.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD')";

        $rows = DB::table('restaurant_table_customers as rtc')
            ->join('restaurant_tables as rt', 'rt.id', '=', 'rtc.restaurant_table_id')
            ->where('rtc.user_id', $userId)
            ->where('rtc.status', TableCustomerStatus::APPROVED->value)
            ->whereNotNull('rt.closed_at')
            ->whereNull('rt.deleted_at')
            ->whereExists(fn (Builder $q) => $q->from('restaurant_table_product as rtp')
                ->whereColumn('rtp.restaurant_table_id', 'rt.id')
                ->where('rtp.quantity', '>', 0))
            ->selectRaw("rt.user_id as restaurant_id, {$day} as day", [$this->timezone])
            ->groupByRaw('1, 2')
            ->orderByRaw('1, 2')
            ->get();

        $days = [];
        foreach ($rows as $row) {
            $days[(int) $row->restaurant_id][] = (string) $row->day;
        }

        return $days;
    }

    /**
     * Each restaurant's ladder, ascending by visits (the default one where the
     * restaurant never set its own).
     *
     * @param  list<int>  $restaurantIds
     * @return array<int, list<Tier>>
     */
    public static function tiersFor(array $restaurantIds): array
    {
        $own = $restaurantIds === [] ? collect() : DB::table('loyalty_tiers')
            ->whereIn('restaurant_id', $restaurantIds)
            ->orderBy('visits')
            ->get(['restaurant_id', 'visits', 'discount_type', 'discount_value', 'max_discount'])
            ->groupBy('restaurant_id');

        $tiers = [];
        foreach ($restaurantIds as $id) {
            $rows = $own->get($id);
            $tiers[$id] = $rows === null
                ? self::defaultTiers()
                : $rows->map(fn (\stdClass $t) => [
                    'visits' => (int) $t->visits,
                    'discount_type' => (string) $t->discount_type,
                    'discount_value' => (float) $t->discount_value,
                    'max_discount' => $t->max_discount === null ? null : (float) $t->max_discount,
                ])->values()->all();
        }

        return $tiers;
    }

    /** @return list<Tier> */
    public static function defaultTiers(): array
    {
        /** @var list<array<string, mixed>> $tiers */
        $tiers = (array) config('incentives.loyalty.default_tiers');

        return array_map(fn (array $t) => [
            'visits' => (int) $t['visits'],
            'discount_type' => (string) $t['discount_type'],
            'discount_value' => (float) $t['discount_value'],
            'max_discount' => isset($t['max_discount']) ? (float) $t['max_discount'] : null,
        ], $tiers);
    }

    /**
     * Splits ascending visit days into streaks: a gap longer than the streak
     * window starts a new one.
     *
     * @param  list<string>  $days
     * @return list<list<string>>
     */
    public function streaks(array $days): array
    {
        $streaks = [];
        $current = [];
        $last = null;

        foreach ($days as $day) {
            if ($last !== null && $this->daysBetween($last, $day) > $this->streakDays) {
                $streaks[] = $current;
                $current = [];
            }
            $current[] = $day;
            $last = $day;
        }

        if ($current !== []) {
            $streaks[] = $current;
        }

        return $streaks;
    }

    /**
     * Hands out the rewards the customer has reached and doesn't have yet, at
     * restaurants that have the incentive on.
     *
     * @param  array<int, list<string>>|null  $visitDays  already loaded visit days, to skip the query
     */
    public function sync(int $userId, ?array $visitDays = null): void
    {
        $visitDays ??= $this->visitDays($userId);
        $enabled = Incentives::enabledSince(Incentive::LOYALTY, array_keys($visitDays));

        if ($enabled === []) {
            return;
        }

        $tiers = self::tiersFor(array_keys($enabled));
        $granted = DB::table('loyalty_rewards')
            ->where('user_id', $userId)
            ->whereIn('restaurant_id', array_keys($enabled))
            ->get(['restaurant_id', 'earned_on'])
            ->map(fn (object $r) => $r->restaurant_id.':'.substr((string) $r->earned_on, 0, 10))
            ->flip();

        foreach ($enabled as $restaurantId => $since) {
            foreach ($this->streaks($visitDays[$restaurantId]) as $streak) {
                foreach ($this->checkpoints($this->qualifying($streak, $since), $tiers[$restaurantId]) as [$earnedOn, $tier]) {
                    if (! $granted->has($restaurantId.':'.$earnedOn)) {
                        $this->grant($userId, $restaurantId, $earnedOn, $tier);
                    }
                }
            }
        }
    }

    /**
     * The loyalty cards to show: one per place visited within the streak window.
     *
     * @param  array<int, list<string>>|null  $visitDays
     * @return list<array<string, mixed>>
     */
    public function cards(int $userId, ?array $visitDays = null): array
    {
        $visitDays ??= $this->visitDays($userId);
        $today = CarbonImmutable::now($this->timezone)->toDateString();
        $active = [];

        foreach ($visitDays as $restaurantId => $days) {
            $streaks = $this->streaks($days);
            $current = $streaks[count($streaks) - 1];
            $last = $current[count($current) - 1];
            $since = $this->daysBetween($last, $today);

            if ($since <= $this->streakDays) {
                $active[$restaurantId] = ['streak' => $current, 'last' => $last, 'since' => $since];
            }
        }

        if ($active === []) {
            return [];
        }

        $restaurantIds = array_keys($active);
        $enabled = Incentives::enabledSince(Incentive::LOYALTY, $restaurantIds);
        $tiersByRestaurant = self::tiersFor($restaurantIds);
        $restaurants = User::whereIn('id', $restaurantIds)->get(['id', 'name', 'handle', 'logo', 'avatar'])->keyBy('id');
        $cards = [];

        foreach ($active as $restaurantId => $card) {
            $restaurant = $restaurants->get($restaurantId);
            if ($restaurant === null) {
                continue;
            }

            $since = $enabled[$restaurantId] ?? null;
            $tiers = $tiersByRestaurant[$restaurantId];
            $lap = $tiers[count($tiers) - 1]['visits'];
            $counted = $since === null ? 0 : count($this->qualifying($card['streak'], $since));
            $position = $counted % $lap;
            // The last visit reached a tier (the ladder's top leaves the position at 0).
            $justEarned = $counted > 0 && ($position === 0 || in_array($position, array_column($tiers, 'visits'), true));

            $cards[] = [
                'restaurant' => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'handle' => $restaurant->handle,
                    'logo_url' => $restaurant->logo_url ?? $restaurant->avatar_url,
                ],
                'streak' => count($card['streak']),
                'last_visit' => $card['last'],
                // Last day a visit still keeps the streak.
                'expires_on' => CarbonImmutable::parse($card['last'], $this->timezone)->addDays($this->streakDays)->toDateString(),
                'days_left' => $this->streakDays - $card['since'],
                'rewards_enabled' => $since !== null,
                'segments' => $lap,
                // Where the customer is on the ladder; a completed ladder shows full.
                'progress' => $justEarned && $position === 0 ? $lap : $position,
                'just_earned' => $justEarned,
                'rewards_earned' => count($this->checkpoints(
                    $since === null ? [] : $this->qualifying($card['streak'], $since),
                    $tiers,
                )),
                'tiers' => $tiers,
            ];
        }

        // Closest to losing the streak first: that's where a visit matters most.
        usort($cards, fn (array $a, array $b) => [$a['days_left'], $b['streak']] <=> [$b['days_left'], $a['streak']]);

        return $cards;
    }

    /**
     * Longest streak the customer ever had at any place.
     *
     * @param  array<int, list<string>>  $visitDays
     */
    public function bestStreak(array $visitDays): int
    {
        $best = 0;
        foreach ($visitDays as $days) {
            foreach ($this->streaks($days) as $streak) {
                $best = max($best, count($streak));
            }
        }

        return $best;
    }

    /**
     * Visits of a streak that count toward rewards: those since the restaurant
     * switched the incentive on.
     *
     * @param  list<string>  $streak
     * @return list<string>
     */
    private function qualifying(array $streak, CarbonImmutable $since): array
    {
        $from = $since->setTimezone($this->timezone)->toDateString();

        return array_values(array_filter($streak, fn (string $day) => $day >= $from));
    }

    /**
     * The visits that reached a tier, with that tier. The ladder repeats after
     * its last tier.
     *
     * @param  list<string>  $qualifying
     * @param  list<Tier>  $tiers
     * @return list<array{0: string, 1: Tier}>
     */
    private function checkpoints(array $qualifying, array $tiers): array
    {
        $lap = $tiers[count($tiers) - 1]['visits'];
        $byVisits = array_column($tiers, null, 'visits');
        $reached = [];

        foreach ($qualifying as $i => $day) {
            $position = ($i % $lap) + 1;
            if (isset($byVisits[$position])) {
                $reached[] = [$day, $byVisits[$position]];
            }
        }

        return $reached;
    }

    /**
     * @param  Tier  $tier
     */
    private function grant(int $userId, int $restaurantId, string $earnedOn, array $tier): void
    {
        DB::transaction(function () use ($userId, $restaurantId, $earnedOn, $tier) {
            $now = now();

            // Claims the checkpoint first: of two syncs racing, only one gets past here.
            $claimed = DB::table('loyalty_rewards')->insertOrIgnore([
                'user_id' => $userId,
                'restaurant_id' => $restaurantId,
                'earned_on' => $earnedOn,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($claimed === 0) {
                return;
            }

            $coupon = Coupon::create([
                'user_id' => $restaurantId,
                'customer_id' => $userId,
                'code' => 'FIEL-'.Str::upper(Str::random(8)),
                'discount_type' => CouponDiscountType::from($tier['discount_type']),
                'discount_value' => $tier['discount_value'],
                'max_discount' => $tier['max_discount'],
                'expires_at' => CarbonImmutable::now($this->timezone)
                    ->addDays((int) config('incentives.loyalty.reward_valid_days'))
                    ->endOfDay()
                    ->utc(),
                'max_uses' => 1,
                // Their own reward: usable on their part of the bill even if a
                // friend at the table used another coupon.
                'multiple_per_table' => true,
            ]);

            DB::table('loyalty_rewards')
                ->where('user_id', $userId)
                ->where('restaurant_id', $restaurantId)
                ->where('earned_on', $earnedOn)
                ->update(['coupon_id' => $coupon->id]);
        });
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) round(CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')));
    }
}
