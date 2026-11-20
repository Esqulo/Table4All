<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\Incentive;
use App\Enums\OnlinePaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoyaltyTiersRequest;
use App\Support\Incentives;
use App\Support\Loyalty\LoyaltyProgram;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The restaurant's "Incentivos" page: customer incentives it offers (and pays
 * for), each switched on or off, with what they've cost so far.
 */
class IncentiveController extends Controller
{
    public function index(Request $request): Response
    {
        $restaurantId = $request->user()->effectiveRestaurantId();
        $enabledSince = Incentives::enabledSince(Incentive::LOYALTY, [$restaurantId]);

        $rewards = DB::table('loyalty_rewards')
            ->where('restaurant_id', $restaurantId)
            ->selectRaw('COUNT(*) as granted, COUNT(DISTINCT user_id) as customers')
            ->first();

        // Rewards customers actually used: what the restaurant gave in discounts.
        $redeemed = DB::table('loyalty_rewards as lr')
            ->join('online_payments as op', 'op.coupon_id', '=', 'lr.coupon_id')
            ->where('lr.restaurant_id', $restaurantId)
            ->where('op.status', OnlinePaymentStatus::PAID->value)
            ->selectRaw('COUNT(*) as uses, COALESCE(SUM(op.discount_cents), 0) as discount_cents')
            ->first();

        return Inertia::render('restaurant/incentives', [
            'incentives' => [
                [
                    'key' => Incentive::LOYALTY->value,
                    'enabled' => isset($enabledSince[$restaurantId]),
                    'enabled_at' => isset($enabledSince[$restaurantId])
                        ? $enabledSince[$restaurantId]->toIso8601ZuluString()
                        : null,
                    'tiers' => LoyaltyProgram::tiersFor([$restaurantId])[$restaurantId],
                    'rules' => [
                        'streak_days' => (int) config('incentives.loyalty.streak_days'),
                        'reward_valid_days' => (int) config('incentives.loyalty.reward_valid_days'),
                        'max_tiers' => (int) config('incentives.loyalty.max_tiers'),
                        'max_visits' => (int) config('incentives.loyalty.max_visits'),
                    ],
                    'stats' => [
                        'rewards_granted' => (int) ($rewards->granted ?? 0),
                        'customers' => (int) ($rewards->customers ?? 0),
                        'rewards_used' => (int) ($redeemed->uses ?? 0),
                        'discount_given' => ((int) ($redeemed->discount_cents ?? 0)) / 100,
                    ],
                ],
            ],
        ]);
    }

    public function update(Request $request, Incentive $incentive): RedirectResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        Incentives::set($request->user()->effectiveRestaurantId(), $incentive, (bool) $enabled);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $enabled ? 'incentives.msg_enabled' : 'incentives.msg_disabled',
        ]);

        return to_route('restaurant.incentives.index');
    }

    /**
     * Saves the loyalty ladder as a whole (it replaces the previous one). The
     * new tiers apply to streaks in progress too.
     */
    public function updateLoyaltyTiers(LoyaltyTiersRequest $request): RedirectResponse
    {
        $restaurantId = $request->user()->effectiveRestaurantId();
        $now = now();

        $rows = collect($request->tiers())
            ->sortBy('visits')
            ->map(fn (array $tier) => [
                'restaurant_id' => $restaurantId,
                ...$tier,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        DB::transaction(function () use ($restaurantId, $rows) {
            DB::table('loyalty_tiers')->where('restaurant_id', $restaurantId)->delete();
            DB::table('loyalty_tiers')->insert($rows);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'incentives.msg_tiers_saved']);

        return to_route('restaurant.incentives.index');
    }
}
