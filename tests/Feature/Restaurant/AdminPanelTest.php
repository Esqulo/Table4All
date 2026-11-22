<?php

namespace Tests\Feature\Restaurant;

use App\Enums\OnlinePaymentStatus;
use App\Enums\QueueItemStatus;
use App\Mail\RestaurantWelcomeMail;
use App\Models\FeeTier;
use App\Models\OnlinePayment;
use App\Models\RestaurantTable;
use App\Models\TablePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private function paidTable(User $restaurant, float $amount, array $attributes = []): RestaurantTable
    {
        $table = $this->closedTable($restaurant, $attributes);
        TablePayment::create(['restaurant_table_id' => $table->id, 'method' => 'pix', 'amount' => $amount]);

        return $table;
    }

    private function onlinePayment(User $restaurant, RestaurantTable $table, array $attributes = []): OnlinePayment
    {
        return OnlinePayment::create([
            'restaurant_table_id' => $table->id,
            'restaurant_id' => $restaurant->id,
            'provider' => 'stripe',
            'account_id' => 'acct_x',
            'status' => OnlinePaymentStatus::PAID,
            'amount_cents' => 10000,
            'charge_cents' => 10000,
            'fee_tier' => 'default',
            'fee_rate_bps' => 450,
            'fee_cents' => 450,
            'application_fee_cents' => 450,
            'paid_at' => now(),
            ...$attributes,
        ]);
    }

    // ─── Access ───────────────────────────────────────────────────────────────

    public function test_only_admins_reach_the_panel(): void
    {
        $restaurant = $this->restaurant();
        $tier = FeeTier::where('key', 'founder')->sole();

        foreach ([$restaurant, $this->waiterOf($restaurant), $this->customer()] as $user) {
            $this->actingAs($user)->get(route('admin.overview'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.tiers.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.restaurants.show', $restaurant))->assertForbidden();
            $this->actingAs($user)->post(route('admin.restaurants.store'), ['name' => 'X', 'email' => 'x@x.com', 'fee_tier' => 'default', 'platform_pays_stripe_fees' => false])->assertForbidden();
            $this->actingAs($user)->post(route('admin.tiers.store'), ['name' => 'VIP', 'fee_percent' => 1])->assertForbidden();
            $this->actingAs($user)->put(route('admin.tiers.update', $tier), ['name' => 'Fundador', 'fee_percent' => 9])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.tiers.destroy', $tier))->assertForbidden();
        }

        $this->assertSame(2, FeeTier::count());
        $this->assertSame('0.00', $tier->refresh()->fee_percent);
        $this->assertFalse(User::where('email', 'x@x.com')->exists());

        auth()->logout();
        $this->get(route('admin.overview'))->assertRedirect(route('admin.login'));
    }

    public function test_admin_root_leads_to_the_overview(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertRedirect('/admin/painel');
    }

    // ─── Overview ─────────────────────────────────────────────────────────────

    public function test_overview_counts_people_without_listing_them(): void
    {
        $admin = $this->admin();
        $active = User::factory()->create(['email' => 'ativa@example.com', 'last_login_at' => now()->subHours(2), 'last_seen_at' => now()->subHour()]);
        User::factory()->create(['last_login_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3)]);
        User::factory()->create(['last_login_at' => now()->subDays(20), 'last_seen_at' => now()->subDays(20)]);
        User::factory()->create(['created_at' => now()->subDays(60)]);
        $restaurant = $this->restaurant();
        $this->waiterOf($restaurant);

        $response = $this->actingAs($admin)->get(route('admin.overview'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('admin/overview')
            ->where('users.total', 7)
            ->where('users.by_type.customer', 4)
            ->where('users.by_type.restaurant', 1)
            ->where('users.by_type.waiter', 1)
            ->where('users.by_type.admin', 1)
            ->where('users.logins', ['day' => 1, 'week' => 2, 'month' => 3])
            ->where('users.active', ['day' => 1, 'week' => 2, 'month' => 3])
            ->where('users.signups.month', 6)
            ->where('users.customers_active_week', 2));

        // Counted, never listed: no customer's address or name reaches the page.
        $response->assertDontSee('ativa@example.com');
        $response->assertDontSee($active->name);
    }

    public function test_overview_sums_orders_and_payments_by_period(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $other = $this->restaurant(['name' => 'Cantina']);
        $product = $this->product($restaurant);

        $recent = $this->paidTable($restaurant, 100);
        $this->queueItem($recent, $product, ['quantity' => 3]);
        $this->paidTable($other, 40);

        // 45 days ago: only in the all-time totals and the previous period.
        $this->travelTo(now()->subDays(45), function () use ($restaurant, $product) {
            $old = $this->paidTable($restaurant, 60);
            $this->queueItem($old, $product, ['quantity' => 2, 'status' => QueueItemStatus::ABANDONED->value]);
        });

        // A deleted table counts nowhere.
        $this->paidTable($restaurant, 999)->delete();

        // R$ 100 paid in the app: 4.5% commission, minus R$ 1 of Stripe's fee given back.
        $this->onlinePayment($restaurant, $recent)->forceFill(['fee_refund_cents' => 100])->save();

        $this->actingAs($this->admin())->get(route('admin.overview'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.tables.total', 3)
                ->where('activity.tables.month', 2)
                ->where('activity.tables.previous', 1)
                ->where('activity.items.total', 5)
                ->where('activity.items.month', 3)
                ->where('activity.payments.total', 200)
                ->where('activity.payments.month', 140)
                ->where('activity.payments.previous', 60)
                ->where('activity.payments.avg_ticket_month', 70)
                ->where('activity.online.count_total', 1)
                ->where('activity.online.fee_total', 3.5)
                ->where('restaurants.total', 2)
                ->where('restaurants.operating_month', 2)
                ->has('timeline', 30)
                ->where('top_restaurants.0.name', 'Bar do Zé')
                ->where('top_restaurants.0.revenue', 100)
                ->where('top_restaurants.1.revenue', 40));
    }

    // ─── Restaurant report ────────────────────────────────────────────────────

    public function test_a_restaurants_report_only_counts_its_own_records(): void
    {
        $restaurant = $this->restaurant();
        $other = $this->restaurant();
        $this->waiterOf($restaurant);
        $chopp = $this->product($restaurant, ['name' => 'Chopp']);
        $this->product($other, ['name' => 'Vinho']);

        $table = $this->paidTable($restaurant, 50);
        $this->billLine($table, $chopp, 5, 10);
        $this->openTable($restaurant);
        $this->paidTable($other, 500);

        $this->actingAs($this->admin())->get(route('admin.restaurants.show', $restaurant))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/restaurant')
                ->where('restaurant.id', $restaurant->id)
                ->where('restaurant.effective_fee_percent', 4.5)
                ->where('counts.team', 1)
                ->where('counts.products', 1)
                ->where('counts.open_tables', 1)
                ->where('activity.tables.total', 1)
                ->where('activity.payments.total', 50)
                ->has('top_products', 1)
                ->where('top_products.0.name', 'Chopp')
                ->where('top_products.0.quantity', 5));
    }

    public function test_only_restaurants_have_a_report(): void
    {
        $this->actingAs($this->admin())->get(route('admin.restaurants.show', $this->customer()))->assertNotFound();
    }

    // ─── Creating restaurants ─────────────────────────────────────────────────

    public function test_admin_creates_a_restaurant_that_gets_an_email_to_choose_its_password(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())->post(route('admin.restaurants.store'), [
            'name' => 'Boteco Novo',
            'email' => 'dono@boteco.com',
            'phone' => '11999990000',
            'fee_tier' => 'founder',
            'fee_percent_override' => '1.5',
            'platform_pays_stripe_fees' => '1',
        ])->assertSessionHasNoErrors();

        $restaurant = User::where('email', 'dono@boteco.com')->sole();
        $this->assertSame('restaurant', $restaurant->account_type->value);
        $this->assertNotNull($restaurant->email_verified_at);
        $this->assertNotNull($restaurant->handle);
        $this->assertSame('founder', $restaurant->feeTier());
        $this->assertSame(150, $restaurant->feeRateBps());
        $this->assertTrue($restaurant->platform_pays_stripe_fees);

        Mail::assertSent(RestaurantWelcomeMail::class, fn (RestaurantWelcomeMail $mail) => $mail->hasTo('dono@boteco.com'));
        $this->assertTrue(DB::table('password_reset_tokens')->where('email', 'dono@boteco.com')->exists());
    }

    public function test_creating_a_restaurant_validates_email_tier_and_fee(): void
    {
        Mail::fake();
        $taken = $this->customer();
        $admin = $this->admin();
        $form = ['name' => 'X', 'email' => 'novo@x.com', 'fee_tier' => 'default', 'platform_pays_stripe_fees' => '0'];

        $this->actingAs($admin)->post(route('admin.restaurants.store'), [...$form, 'email' => $taken->email])->assertSessionHasErrors('email');
        $this->actingAs($admin)->post(route('admin.restaurants.store'), [...$form, 'fee_tier' => 'vip'])->assertSessionHasErrors('fee_tier');
        $this->actingAs($admin)->post(route('admin.restaurants.store'), [...$form, 'fee_percent_override' => '31'])->assertSessionHasErrors('fee_percent_override');
        $this->actingAs($admin)->post(route('admin.restaurants.store'), [...$form, 'fee_percent_override' => '-1'])->assertSessionHasErrors('fee_percent_override');

        $this->assertFalse(User::where('email', 'novo@x.com')->exists());
        Mail::assertNothingSent();
    }

    // ─── Terms ────────────────────────────────────────────────────────────────

    public function test_admin_sets_and_clears_a_restaurants_own_fee(): void
    {
        $restaurant = $this->restaurant();
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.restaurants.update', $restaurant), [
            'fee_tier' => 'default', 'platform_pays_stripe_fees' => false, 'fee_percent_override' => '2',
        ])->assertSessionHasNoErrors();
        $this->assertSame(200, $restaurant->refresh()->feeRateBps());

        // The list's quick edits don't send the own fee: it stays.
        $this->actingAs($admin)->patch(route('admin.restaurants.update', $restaurant), [
            'fee_tier' => 'founder', 'platform_pays_stripe_fees' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(200, $restaurant->refresh()->feeRateBps());
        $this->assertSame('founder', $restaurant->fee_tier);

        // Empty: back to the plan's.
        $this->actingAs($admin)->patch(route('admin.restaurants.update', $restaurant), [
            'fee_tier' => 'founder', 'platform_pays_stripe_fees' => true, 'fee_percent_override' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($restaurant->refresh()->fee_percent_override);
        $this->assertSame(0, $restaurant->feeRateBps());

        $this->actingAs($admin)->patch(route('admin.restaurants.update', $restaurant), [
            'fee_tier' => 'founder', 'platform_pays_stripe_fees' => true, 'fee_percent_override' => '1.234',
        ])->assertSessionHasErrors('fee_percent_override');
    }

    public function test_the_list_filters_by_plan_and_counts_removed_tiers_as_default(): void
    {
        $this->restaurant(['name' => 'Padrão']);
        $this->restaurant(['name' => 'Fundador', 'fee_tier' => 'founder']);
        $this->restaurant(['name' => 'Órfão', 'fee_tier' => 'gone']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.restaurants.index', ['tier' => 'founder']))
            ->assertInertia(fn (Assert $page) => $page->has('restaurants.data', 1)->where('restaurants.data.0.name', 'Fundador'));

        $this->actingAs($admin)->get(route('admin.restaurants.index', ['tier' => 'default']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('restaurants.data', 2)
                ->where('restaurants.data.0.fee_tier', 'default')
                ->where('restaurants.data.1.fee_tier', 'default'));
    }

    // ─── Plans ────────────────────────────────────────────────────────────────

    public function test_admin_creates_edits_and_deletes_plans(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.tiers.store'), ['name' => 'Parceiro Sul', 'fee_percent' => '2.75'])
            ->assertSessionHasNoErrors();
        $tier = FeeTier::where('name', 'Parceiro Sul')->sole();
        $this->assertSame('parceiro_sul', $tier->key);

        $this->actingAs($admin)->post(route('admin.tiers.store'), ['name' => 'Parceiro Sul', 'fee_percent' => '1'])
            ->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('admin.tiers.store'), ['name' => 'Caro', 'fee_percent' => '50'])
            ->assertSessionHasErrors('fee_percent');

        $restaurant = $this->restaurant(['fee_tier' => 'parceiro_sul']);
        $this->assertSame(275, $restaurant->feeRateBps());

        // The key never changes, so restaurants keep pointing at it.
        $this->actingAs($admin)->put(route('admin.tiers.update', $tier), ['name' => 'Parceiro', 'fee_percent' => '3'])
            ->assertSessionHasNoErrors();
        $this->assertSame('parceiro_sul', $tier->refresh()->key);
        $this->assertSame(300, $restaurant->refresh()->feeRateBps());

        $this->actingAs($admin)->get(route('admin.tiers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/fee-tiers')
                ->has('tiers', 3)
                // Cheapest first: founder 0%, this one 3%, default 4.5%.
                ->where('tiers.1.key', 'parceiro_sul')
                ->where('tiers.1.restaurants', 1));

        // In use: stays.
        $this->actingAs($admin)->delete(route('admin.tiers.destroy', $tier))->assertSessionHasErrors('tier');
        $this->assertTrue(FeeTier::whereKey($tier->id)->exists());

        $restaurant->forceFill(['fee_tier' => 'default'])->save();
        $this->actingAs($admin)->delete(route('admin.tiers.destroy', $tier))->assertSessionHasNoErrors();
        $this->assertFalse(FeeTier::whereKey($tier->id)->exists());
    }

    public function test_the_default_plan_cannot_be_deleted(): void
    {
        $default = FeeTier::where('key', 'default')->sole();

        $this->actingAs($this->admin())->delete(route('admin.tiers.destroy', $default))->assertSessionHasErrors('tier');

        $this->assertTrue(FeeTier::whereKey($default->id)->exists());
    }

    public function test_the_finance_page_shows_the_plans_name(): void
    {
        FeeTier::where('key', 'founder')->update(['name' => 'Fundador']);
        $restaurant = $this->restaurant(['fee_tier' => 'founder']);

        $this->actingAs($restaurant)->get(route('restaurant.finance.show'))
            ->assertInertia(fn (Assert $page) => $page->where('tier', 'founder')->where('tierName', 'Fundador'));
    }

    // ─── Activity tracking ────────────────────────────────────────────────────

    public function test_signing_in_records_when(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertNotNull($user->last_seen_at);
    }

    public function test_being_seen_is_written_at_most_every_few_minutes(): void
    {
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(2)]);
        $before = $user->last_seen_at;

        $this->actingAs($user)->get(route('social.feed'))->assertOk();
        $this->assertEquals($before->getTimestamp(), $user->refresh()->last_seen_at?->getTimestamp());

        $user->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        $this->actingAs($user->refresh())->get(route('social.feed'))->assertOk();
        $this->assertTrue($user->refresh()->last_seen_at->greaterThan(now()->subMinute()));
    }

    // ─── make-admin ───────────────────────────────────────────────────────────

    public function test_make_admin_promotes_a_customer_and_refuses_restaurants(): void
    {
        $customer = $this->customer();
        $restaurant = $this->restaurant();

        $this->artisan('app:make-admin', ['email' => $customer->email])->assertSuccessful();
        $this->assertSame('admin', $customer->refresh()->account_type->value);

        $this->artisan('app:make-admin', ['email' => $restaurant->email])->assertFailed();
        $this->assertSame('restaurant', $restaurant->refresh()->account_type->value);

        $this->artisan('app:make-admin', ['email' => 'Novo@Admin.com'])->assertSuccessful();
        $admin = User::where('email', 'novo@admin.com')->sole();
        $this->assertSame('admin', $admin->account_type->value);
        $this->assertNotNull($admin->email_verified_at);
    }
}
