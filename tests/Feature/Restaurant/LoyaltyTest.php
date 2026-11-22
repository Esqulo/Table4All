<?php

namespace Tests\Feature\Restaurant;

use App\Enums\Incentive;
use App\Enums\OnlinePaymentStatus;
use App\Models\Coupon;
use App\Models\OnlinePayment;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Support\Incentives;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * The customer's page (level, loyalty cards, reward coupons, history) and the
 * restaurant's incentives that pay for the rewards.
 */
class LoyaltyTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // 15:00 in Brasilia, Friday 2026-11-20.
        Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The customer sat at one of the restaurant's tables at $utc (approved),
     * and the table was then closed with $bill on it.
     */
    private function visit(User $customer, User $restaurant, string $utc, float $bill = 50, string $status = 'approved'): int
    {
        $table = $this->closedTable($restaurant);
        $table->forceFill(['closed_at' => Carbon::parse($utc, 'UTC')->addHour()])->save();
        if ($bill > 0) {
            $this->billLine($table, $this->product($restaurant), 1, $bill, $customer->id);
        }
        DB::table('restaurant_table_customers')->insert([
            'restaurant_table_id' => $table->id,
            'user_id' => $customer->id,
            'status' => $status,
            'created_at' => Carbon::parse($utc, 'UTC'),
            'updated_at' => Carbon::parse($utc, 'UTC'),
        ]);

        return $table->id;
    }

    private function enableLoyalty(User $restaurant, string $sinceUtc = '2026-01-01 12:00:00'): void
    {
        Carbon::setTestNow(Carbon::parse($sinceUtc, 'UTC'));
        Incentives::set($restaurant->id, Incentive::LOYALTY, true);
        Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', 'UTC'));
    }

    private function dashboard(User $customer): TestResponse
    {
        return $this->actingAs($customer)->get(route('customer.rewards'));
    }

    // ── loyalty card ─────────────────────────────────────────────────────────

    public function test_three_visits_earn_a_ten_real_coupon_paid_by_the_restaurant(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        $this->visit($customer, $restaurant, '2026-11-01 22:00:00');
        $this->visit($customer, $restaurant, '2026-11-08 22:00:00');
        $this->visit($customer, $restaurant, '2026-11-15 22:00:00');

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->component('customer/rewards')
            ->has('loyalty', 1)
            ->where('loyalty.0.restaurant.name', 'Bar do Zé')
            ->where('loyalty.0.streak', 3)
            ->where('loyalty.0.rewards_enabled', true)
            ->where('loyalty.0.rewards_earned', 1)
            // Just reached the top of the ladder: the bar shows full until the next visit.
            ->where('loyalty.0.progress', 3)
            ->where('loyalty.0.just_earned', true)
            ->where('loyalty.0.segments', 3)
            ->where('loyalty.0.tiers.0.visits', 3)
            ->has('wallet', 1)
            ->where('wallet.0.value', 10)
            ->where('wallet.0.status', 'available')
        );

        $coupon = Coupon::sole();
        $this->assertSame($restaurant->id, $coupon->user_id);   // the restaurant pays
        $this->assertSame($customer->id, $coupon->customer_id); // only they can use it
        $this->assertSame('fixed', $coupon->discount_type->value);
        $this->assertSame(10.0, $coupon->discount_value);
        $this->assertSame(1, $coupon->max_uses);
        $this->assertSame('2026-12-20', $coupon->expires_at?->setTimezone('America/Sao_Paulo')->format('Y-m-d'));
    }

    public function test_rewards_are_handed_out_once_however_often_the_page_loads(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
            $this->visit($customer, $restaurant, "{$day} 20:00:00");
        }

        $this->dashboard($customer)->assertOk();
        $this->dashboard($customer)->assertOk();

        $this->assertSame(1, Coupon::count());
        $this->assertSame(1, DB::table('loyalty_rewards')->count());
    }

    public function test_every_three_visits_earn_another_reward_and_the_bar_starts_over(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        foreach (range(1, 7) as $day) {
            $this->visit($customer, $restaurant, sprintf('2026-11-%02d 20:00:00', $day));
        }

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.0.streak', 7)
            ->where('loyalty.0.rewards_earned', 2)
            ->where('loyalty.0.progress', 1)
            ->has('wallet', 2)
        );
    }

    public function test_several_tables_on_the_same_local_day_count_as_one_visit(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        // 12:00 and 22:00 in Brasilia on the 10th, and 01:30 UTC on the 11th (22:30 on the 10th).
        $this->visit($customer, $restaurant, '2026-11-10 15:00:00');
        $this->visit($customer, $restaurant, '2026-11-11 01:00:00');
        $this->visit($customer, $restaurant, '2026-11-11 01:30:00');

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.0.streak', 1)
            ->where('loyalty.0.progress', 1)
            ->where('stats.visits', 1)
            ->has('wallet', 0)
        );
    }

    public function test_only_finished_tables_with_a_bill_where_the_customer_was_admitted_count(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        $this->visit($customer, $restaurant, '2026-11-10 20:00:00', bill: 0);                     // empty table
        $this->visit($customer, $restaurant, '2026-11-11 20:00:00', status: 'pending');           // never let in
        $this->visit($customer, $restaurant, '2026-11-12 20:00:00', status: 'rejected');
        $deleted = $this->visit($customer, $restaurant, '2026-11-13 20:00:00');
        DB::table('restaurant_tables')->where('id', $deleted)->update(['deleted_at' => now()]);
        $open = $this->openTable($restaurant);                                                    // still open
        $this->billLine($open, $this->product($restaurant), 1, 10, $customer->id);
        DB::table('restaurant_table_customers')->insert([
            'restaurant_table_id' => $open->id, 'user_id' => $customer->id, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->has('loyalty', 0)
            ->where('stats.visits', 0)
        );
    }

    public function test_staying_away_longer_than_thirty_days_loses_the_whole_streak(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        $this->visit($customer, $restaurant, '2026-09-01 20:00:00');
        $this->visit($customer, $restaurant, '2026-09-05 20:00:00');
        // 36 days later: a new streak.
        $this->visit($customer, $restaurant, '2026-10-11 20:00:00');
        $this->visit($customer, $restaurant, '2026-11-10 20:00:00'); // exactly 30 days: kept

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.0.streak', 2)
            ->where('loyalty.0.progress', 2)
            ->where('loyalty.0.last_visit', '2026-11-10')
            ->where('loyalty.0.expires_on', '2026-12-10')
            ->where('loyalty.0.days_left', 20)
            ->where('stats.best_streak', 2)
            ->has('wallet', 0)
        );
    }

    public function test_places_not_visited_in_the_last_thirty_days_have_no_card(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->visit($customer, $restaurant, '2026-10-19 20:00:00'); // 32 days ago

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->has('loyalty', 0)
            ->where('stats.visits', 1)
            ->where('stats.places', 1)
        );
    }

    public function test_no_rewards_where_the_restaurant_has_the_incentive_off(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
            $this->visit($customer, $restaurant, "{$day} 20:00:00");
        }

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.0.streak', 3)
            ->where('loyalty.0.rewards_enabled', false)
            ->where('loyalty.0.progress', 0)
            ->where('loyalty.0.rewards_earned', 0)
            ->has('wallet', 0)
        );
        $this->assertSame(0, Coupon::count());
    }

    public function test_only_visits_since_the_incentive_was_switched_on_count(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->visit($customer, $restaurant, '2026-11-01 20:00:00');
        $this->visit($customer, $restaurant, '2026-11-02 20:00:00');
        $this->enableLoyalty($restaurant, '2026-11-05 12:00:00');
        $this->visit($customer, $restaurant, '2026-11-06 20:00:00');

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.0.streak', 3)
            ->where('loyalty.0.progress', 1)
            ->has('wallet', 0)
        );
    }

    public function test_switching_the_incentive_off_stops_new_rewards(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
            $this->visit($customer, $restaurant, "{$day} 20:00:00");
        }
        Incentives::set($restaurant->id, Incentive::LOYALTY, false);

        $this->dashboard($customer)->assertOk();
        $this->assertSame(0, Coupon::count());
    }

    public function test_each_place_has_its_own_card(): void
    {
        $bar = $this->restaurant(['name' => 'Bar']);
        $pizza = $this->restaurant(['name' => 'Pizzaria']);
        $customer = $this->customer();
        $this->visit($customer, $bar, '2026-11-18 20:00:00');           // 2 days ago
        $this->visit($customer, $pizza, '2026-10-25 20:00:00');         // 26 days ago: about to expire
        $this->visit($this->customer(), $pizza, '2026-11-19 20:00:00'); // someone else

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->has('loyalty', 2)
            ->where('loyalty.0.restaurant.name', 'Pizzaria')  // closest to losing it first
            ->where('loyalty.0.days_left', 4)
            ->where('loyalty.1.restaurant.name', 'Bar')
            ->where('stats.places', 2)
        );
    }

    // ── the rest of the customer page ────────────────────────────────────────

    public function test_history_lists_the_customers_tables_with_what_they_ordered_and_paid(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $customer = $this->customer();
        $tableId = $this->visit($customer, $restaurant, '2026-11-10 20:00:00', bill: 30);
        // A friend's order on the same table.
        $this->billLine(RestaurantTable::findOrFail($tableId), $this->product($restaurant), 2, 10, $this->customer()->id);
        OnlinePayment::create([
            'restaurant_table_id' => $tableId,
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_x',
            'status' => OnlinePaymentStatus::PAID,
            'amount_cents' => 3000,
            'discount_cents' => 1000,
            'charge_cents' => 2000,
            'fee_tier' => 'standard',
            'fee_rate_bps' => 0,
            'fee_cents' => 0,
            'application_fee_cents' => 0,
            'paid_at' => Carbon::parse('2026-11-10 22:00:00', 'UTC'),
        ]);
        $this->visit($this->customer(), $restaurant, '2026-11-11 20:00:00'); // not theirs

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('history.total', 1)
            ->where('history.data.0.restaurant.name', 'Bar do Zé')
            ->where('history.data.0.table_total', 50)
            ->where('history.data.0.my_total', 30)
            ->where('history.data.0.code', null)
            ->has('history.data.0.payments', 1)
            ->where('history.data.0.payments.0.charged', 20)
            ->where('history.data.0.payments.0.discount', 10)
            ->where('stats.paid_in_app', 20)
            ->where('stats.saved', 10)
            ->where('stats.coupons_used', 0)
        );
    }

    public function test_history_is_paginated(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        foreach (range(1, 12) as $day) {
            $this->visit($customer, $restaurant, sprintf('2026-10-%02d 20:00:00', $day));
        }

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 10)
            ->where('history.last_page', 2)
        );
        $this->actingAs($customer)->get(route('customer.rewards', ['pagina' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('history.data', 2));
    }

    public function test_level_and_achievements_grow_with_visits_and_spending(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->visit($customer, $restaurant, '2026-11-10 20:00:00', bill: 150); // 100 XP + 150 XP

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('level.xp', 250)
            ->where('level.level', 2)
            ->where('level.level_xp', 200)
            ->where('level.next_level_xp', 800)
            ->where('achievements.0.key', 'first_visit')
            ->where('achievements.0.unlocked', true)
            ->where('achievements.1.key', 'regular')
            ->where('achievements.1.progress', 1)
            ->where('achievements.1.unlocked', false)
        );
    }

    public function test_a_new_customer_sees_an_empty_page(): void
    {
        $this->dashboard($this->customer())->assertInertia(fn (Assert $page) => $page
            ->where('level.level', 1)
            ->has('loyalty', 0)
            ->has('wallet', 0)
            ->where('history.total', 0)
        );
    }

    public function test_staff_cannot_open_the_customer_page(): void
    {
        $restaurant = $this->restaurant();
        $this->actingAs($restaurant)->get(route('customer.rewards'))->assertRedirect(route('dashboard'));
        $this->actingAs($this->waiterOf($restaurant))->get(route('customer.rewards'))->assertRedirect(route('dashboard'));
    }

    // ── personal coupons ─────────────────────────────────────────────────────

    public function test_reward_coupons_stay_out_of_the_restaurants_coupon_list_and_controls(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
            $this->visit($customer, $restaurant, "{$day} 20:00:00");
        }
        $this->dashboard($customer);
        $reward = Coupon::sole();

        $this->actingAs($restaurant)->get(route('restaurant.coupons.index'))
            ->assertInertia(fn (Assert $page) => $page->has('coupons', 0));
        $this->actingAs($restaurant)->delete(route('restaurant.coupons.destroy', $reward))->assertForbidden();
        $this->assertNotSoftDeleted($reward);
    }

    // ── restaurant incentives page ───────────────────────────────────────────

    public function test_restaurant_switches_loyalty_on_and_off(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->get(route('restaurant.incentives.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('restaurant/incentives')
                ->where('incentives.0.key', 'loyalty')
                ->where('incentives.0.enabled', false)
                ->has('incentives.0.tiers', 1)
                ->where('incentives.0.tiers.0.visits', 3)
                ->where('incentives.0.tiers.0.discount_type', 'fixed')
                ->where('incentives.0.tiers.0.discount_value', 10)
            );

        $this->actingAs($restaurant)->put(route('restaurant.incentives.update', 'loyalty'), ['enabled' => true])
            ->assertRedirect(route('restaurant.incentives.index'));
        $this->assertTrue(Incentives::enabled($restaurant->id, Incentive::LOYALTY));

        // Switching on again keeps the original date.
        $since = DB::table('restaurant_incentives')->value('enabled_at');
        Carbon::setTestNow(now()->addDay());
        $this->actingAs($restaurant)->put(route('restaurant.incentives.update', 'loyalty'), ['enabled' => true]);
        $this->assertSame($since, DB::table('restaurant_incentives')->value('enabled_at'));

        $this->actingAs($restaurant)->put(route('restaurant.incentives.update', 'loyalty'), ['enabled' => false]);
        $this->assertFalse(Incentives::enabled($restaurant->id, Incentive::LOYALTY));
    }

    public function test_incentive_requests_are_validated(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->put(route('restaurant.incentives.update', 'loyalty'), [])
            ->assertSessionHasErrors('enabled');
        $this->actingAs($restaurant)->put('/restaurant/incentivos/cashback', ['enabled' => true])
            ->assertNotFound();
    }

    public function test_only_the_restaurant_manages_its_incentives(): void
    {
        $restaurant = $this->restaurant();

        foreach ([$this->waiterOf($restaurant), $this->customer()] as $user) {
            $this->actingAs($user)->put(route('restaurant.incentives.update', 'loyalty'), ['enabled' => true])
                ->assertRedirect(route('dashboard'));
        }
        $this->assertFalse(Incentives::enabled($restaurant->id, Incentive::LOYALTY));
    }

    public function test_incentives_page_reports_rewards_granted_and_used(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
            $tableId = $this->visit($customer, $restaurant, "{$day} 20:00:00");
        }
        $this->dashboard($customer);
        OnlinePayment::create([
            'restaurant_table_id' => $tableId,
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_x',
            'status' => OnlinePaymentStatus::PAID,
            'amount_cents' => 5000,
            'discount_cents' => 1000,
            'charge_cents' => 4000,
            'coupon_id' => Coupon::sole()->id,
            'fee_tier' => 'standard',
            'fee_rate_bps' => 0,
            'fee_cents' => 0,
            'application_fee_cents' => 0,
        ]);

        $this->actingAs($restaurant)->get(route('restaurant.incentives.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('incentives.0.enabled', true)
                ->where('incentives.0.stats.rewards_granted', 1)
                ->where('incentives.0.stats.customers', 1)
                ->where('incentives.0.stats.rewards_used', 1)
                ->where('incentives.0.stats.discount_given', 10)
            );

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('wallet.0.status', 'used')
            ->where('stats.coupons_used', 1)
        );
    }

    // ── reward tiers ─────────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $tiers */
    private function saveTiers(User $restaurant, array $tiers): TestResponse
    {
        return $this->actingAs($restaurant)->put(route('restaurant.incentives.loyalty.tiers'), ['tiers' => $tiers]);
    }

    private function chainedLadder(User $restaurant): void
    {
        $this->saveTiers($restaurant, [
            ['visits' => 10, 'discount_type' => 'fixed', 'discount_value' => 15],
            ['visits' => 3, 'discount_type' => 'fixed', 'discount_value' => 5],
            ['visits' => 30, 'discount_type' => 'fixed', 'discount_value' => 100],
        ])->assertSessionHasNoErrors()->assertRedirect(route('restaurant.incentives.index'));
    }

    public function test_chained_tiers_each_give_their_own_coupon(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        $this->chainedLadder($restaurant);
        // 31 visits, every other day.
        foreach (range(0, 30) as $i) {
            $this->visit($customer, $restaurant, Carbon::parse('2026-09-19 20:00:00', 'UTC')->addDays($i * 2)->toDateTimeString());
        }

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.0.streak', 31)
            ->where('loyalty.0.segments', 30)
            ->where('loyalty.0.progress', 1)   // the ladder started over
            ->where('loyalty.0.rewards_earned', 3)
            ->has('loyalty.0.tiers', 3)
            ->where('loyalty.0.tiers.0.visits', 3)
            ->where('loyalty.0.tiers.1.visits', 10)
            ->where('loyalty.0.tiers.2.visits', 30)
        );

        $this->assertSame([5.0, 15.0, 100.0], Coupon::orderBy('id')->pluck('discount_value')->all());
    }

    public function test_reaching_a_middle_tier_shows_it_was_just_earned(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        $this->chainedLadder($restaurant);
        foreach (range(1, 10) as $day) {
            $this->visit($customer, $restaurant, sprintf('2026-11-%02d 20:00:00', $day));
        }

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.0.progress', 10)
            ->where('loyalty.0.just_earned', true)
            ->where('loyalty.0.rewards_earned', 2)
            ->has('wallet', 2)
        );
    }

    public function test_a_percentage_tier_gives_a_capped_percentage_coupon(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $this->enableLoyalty($restaurant);
        $this->saveTiers($restaurant, [
            ['visits' => 2, 'discount_type' => 'percent', 'discount_value' => 15, 'max_discount' => 20],
        ])->assertSessionHasNoErrors();
        $this->visit($customer, $restaurant, '2026-11-01 20:00:00');
        $this->visit($customer, $restaurant, '2026-11-02 20:00:00');

        $this->dashboard($customer)->assertInertia(fn (Assert $page) => $page
            ->where('wallet.0.discount_type', 'percent')
            ->where('wallet.0.value', 15)
            ->where('wallet.0.max_discount', 20)
        );

        $coupon = Coupon::sole();
        $this->assertSame('percent', $coupon->discount_type->value);
        $this->assertSame(20.0, $coupon->max_discount);
        $this->assertSame(1, $coupon->max_uses);
    }

    public function test_saving_tiers_replaces_the_ladder_and_drops_caps_on_fixed_discounts(): void
    {
        $restaurant = $this->restaurant();
        $other = $this->restaurant();
        $this->saveTiers($other, [['visits' => 4, 'discount_type' => 'fixed', 'discount_value' => 8]]);
        $this->chainedLadder($restaurant);

        $this->saveTiers($restaurant, [
            ['visits' => 5, 'discount_type' => 'fixed', 'discount_value' => 7, 'max_discount' => 3],
        ])->assertSessionHasNoErrors();

        $this->actingAs($restaurant)->get(route('restaurant.incentives.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('incentives.0.tiers', 1)
                ->where('incentives.0.tiers.0.visits', 5)
                ->where('incentives.0.tiers.0.max_discount', null)
            );
        $this->assertSame(1, DB::table('loyalty_tiers')->where('restaurant_id', $other->id)->count());
    }

    public function test_tiers_are_validated(): void
    {
        $restaurant = $this->restaurant();
        $tier = fn (array $over = []) => ['visits' => 3, 'discount_type' => 'fixed', 'discount_value' => 5, ...$over];

        $this->saveTiers($restaurant, [])->assertSessionHasErrors('tiers');
        $this->saveTiers($restaurant, [$tier(), $tier()])->assertSessionHasErrors('tiers.1.visits'); // same visits twice
        $this->saveTiers($restaurant, [$tier(['visits' => 0])])->assertSessionHasErrors('tiers.0.visits');
        $this->saveTiers($restaurant, [$tier(['visits' => 101])])->assertSessionHasErrors('tiers.0.visits');
        $this->saveTiers($restaurant, [$tier(['visits' => 2.5])])->assertSessionHasErrors('tiers.0.visits');
        $this->saveTiers($restaurant, [$tier(['discount_value' => 0])])->assertSessionHasErrors('tiers.0.discount_value');
        $this->saveTiers($restaurant, [$tier(['discount_type' => 'percent', 'discount_value' => 120])])
            ->assertSessionHasErrors('tiers.0.discount_value');
        $this->saveTiers($restaurant, [$tier(['discount_type' => 'gift'])])->assertSessionHasErrors('tiers.0.discount_type');
        $this->saveTiers($restaurant, array_map(fn (int $v) => $tier(['visits' => $v]), range(1, 6)))
            ->assertSessionHasErrors('tiers');

        $this->assertSame(0, DB::table('loyalty_tiers')->count());
    }

    public function test_only_the_restaurant_sets_its_tiers(): void
    {
        $restaurant = $this->restaurant();
        $tiers = [['visits' => 3, 'discount_type' => 'fixed', 'discount_value' => 5]];

        $this->saveTiers($this->waiterOf($restaurant), $tiers)->assertRedirect(route('dashboard'));
        $this->saveTiers($this->customer(), $tiers)->assertRedirect(route('dashboard'));
        $this->assertSame(0, DB::table('loyalty_tiers')->count());
    }
}
