<?php

namespace Tests\Feature\Restaurant;

use App\Models\Coupon;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\StripePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePaymentGateway;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** The restaurant's financial profile and coupons, and the admin's terms per restaurant. */
class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    /** @return array<string, mixed> */
    private function couponForm(array $overrides = []): array
    {
        return [
            'code' => 'bem-vindo10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'max_discount' => 15,
            'expires_on' => '2030-12-31',
            'multiple_per_table' => '1',
            ...$overrides,
        ];
    }

    // ─── Financial profile ────────────────────────────────────────────────────

    public function test_restaurant_connects_a_stripe_account_and_is_sent_to_onboarding(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.finance.connect'))
            ->assertRedirect('https://connect.stripe.test/setup/acct_test_1');

        $this->assertSame('acct_test_1', $restaurant->refresh()->stripe_account_id);
        $this->assertFalse($restaurant->acceptsOnlinePayments());

        // Connecting again reuses the account.
        $this->actingAs($restaurant)->post(route('restaurant.finance.connect'))
            ->assertRedirect('https://connect.stripe.test/setup/acct_test_1');
    }

    public function test_back_from_onboarding_the_account_state_is_read_from_stripe(): void
    {
        $restaurant = $this->restaurant();
        $restaurant->forceFill(['stripe_account_id' => 'acct_x'])->save();
        $this->gateway->accounts['acct_x'] = ['charges_enabled' => true, 'details_submitted' => true];

        $this->actingAs($restaurant)->get(route('restaurant.finance.return'))
            ->assertRedirect(route('restaurant.finance.show'));

        $this->assertTrue($restaurant->refresh()->acceptsOnlinePayments());

        $this->actingAs($restaurant)->get(route('restaurant.finance.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('restaurant/finance')
                ->where('account.charges_enabled', true)
                ->where('tier', 'default')
                ->where('feePercent', 4.5));
    }

    public function test_stripe_account_updates_arrive_by_webhook(): void
    {
        $restaurant = $this->restaurant();
        $restaurant->forceFill(['stripe_account_id' => 'acct_x', 'stripe_charges_enabled' => true])->save();

        // Stripe took card payments away: a thin v2 event names the account, the state is read back.
        $this->gateway->accounts['acct_x'] = ['charges_enabled' => false, 'details_submitted' => true];
        $this->gateway->nextWebhookEvent = [
            'type' => 'v2.core.account[configuration.merchant].capability_status_updated',
            'account' => null,
            'object' => ['id' => 'acct_x'],
        ];
        $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'valid'], '{}')->assertOk();

        $this->assertFalse($restaurant->refresh()->stripe_charges_enabled);

        // ...and when it comes back.
        $this->gateway->accounts['acct_x'] = ['charges_enabled' => true, 'details_submitted' => true];
        $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'valid'], '{}')->assertOk();

        $this->assertTrue($restaurant->refresh()->stripe_charges_enabled);
    }

    public function test_stripe_signatures_are_checked_against_every_configured_secret(): void
    {
        // The platform and the Connect endpoints each sign with their own secret.
        $gateway = new StripePaymentGateway('sk_test_unused', ['whsec_platform', 'whsec_connect'], 'brl');
        $payload = (string) json_encode([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'account' => 'acct_x',
            'data' => ['object' => ['id' => 'cs_1', 'object' => 'checkout.session']],
        ]);
        $sign = fn (string $secret) => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, $secret);

        $event = $gateway->webhookEvent($payload, $sign('whsec_connect'));

        $this->assertSame('checkout.session.completed', $event['type'] ?? null);
        $this->assertSame('acct_x', $event['account'] ?? null);
        $this->assertSame('cs_1', $event['object']['id'] ?? null);
        $this->assertNull($gateway->webhookEvent($payload, $sign('whsec_forged')));
    }

    public function test_waiters_and_customers_cannot_reach_the_financial_profile(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($this->waiterOf($restaurant))->get(route('restaurant.finance.show'))->assertRedirect(route('dashboard'));
        $this->actingAs($this->customer())->post(route('restaurant.finance.connect'))->assertRedirect(route('dashboard'));
        $this->assertSame([], $this->gateway->accounts);
    }

    public function test_the_stripe_account_id_is_not_shared_with_the_browser(): void
    {
        $restaurant = $this->restaurant();
        $restaurant->forceFill(['stripe_account_id' => 'acct_secret'])->save();

        $this->actingAs($restaurant)->get(route('restaurant.finance.show'))
            ->assertInertia(fn (Assert $page) => $page->missing('auth.user.stripe_account_id'));
    }

    // ─── Restaurant coupons ───────────────────────────────────────────────────

    public function test_restaurant_creates_a_coupon(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.coupons.store'), $this->couponForm())
            ->assertRedirect(route('restaurant.coupons.index'));

        $coupon = Coupon::sole();
        $this->assertSame($restaurant->id, $coupon->user_id);
        $this->assertSame('BEM-VINDO10', $coupon->code);
        $this->assertTrue($coupon->multiple_per_table);
        $this->assertSame(15.0, $coupon->max_discount);
        // Valid through the whole last day, in the venue's time zone.
        $this->assertSame('2030-12-31 23:59:59', $coupon->expires_at?->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
    }

    public function test_coupon_codes_are_unique_per_restaurant(): void
    {
        $restaurant = $this->restaurant();
        $this->actingAs($restaurant)->post(route('restaurant.coupons.store'), $this->couponForm());

        $this->actingAs($restaurant)->post(route('restaurant.coupons.store'), $this->couponForm(['code' => 'BEM-VINDO10']))
            ->assertSessionHasErrors('code');

        // Another restaurant (and the platform) may use the same code.
        $this->actingAs($this->restaurant())->post(route('restaurant.coupons.store'), $this->couponForm())
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post(route('admin.coupons.store'), $this->couponForm())
            ->assertSessionHasNoErrors();
    }

    public function test_coupon_values_are_validated(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.coupons.store'), $this->couponForm(['discount_value' => 120]))
            ->assertSessionHasErrors('discount_value');
        $this->actingAs($restaurant)->post(route('restaurant.coupons.store'), $this->couponForm(['discount_value' => 0]))
            ->assertSessionHasErrors('discount_value');
        $this->actingAs($restaurant)->post(route('restaurant.coupons.store'), $this->couponForm(['code' => 'com espaço']))
            ->assertSessionHasErrors('code');
        $this->actingAs($restaurant)->post(route('restaurant.coupons.store'), $this->couponForm(['discount_type' => 'gift']))
            ->assertSessionHasErrors('discount_type');

        $this->assertSame(0, Coupon::count());
    }

    public function test_restaurant_edits_and_deletes_its_coupon(): void
    {
        $restaurant = $this->restaurant();
        $this->actingAs($restaurant)->post(route('restaurant.coupons.store'), $this->couponForm());
        $coupon = Coupon::sole();

        $this->actingAs($restaurant)
            ->put(route('restaurant.coupons.update', $coupon), $this->couponForm(['discount_type' => 'fixed', 'discount_value' => 5, 'max_discount' => null, 'expires_on' => null]))
            ->assertSessionHasNoErrors();

        $coupon->refresh();
        $this->assertSame(5.0, $coupon->discount_value);
        $this->assertNull($coupon->expires_at);
        $this->assertSame('fixed', $coupon->discount_type->value);

        $this->actingAs($restaurant)->delete(route('restaurant.coupons.destroy', $coupon));
        $this->assertSoftDeleted($coupon);
    }

    public function test_a_restaurant_cannot_touch_another_restaurants_or_the_platforms_coupons(): void
    {
        $other = Coupon::create(['user_id' => $this->restaurant()->id, 'code' => 'X1', 'discount_type' => 'percent', 'discount_value' => 5]);
        $platform = Coupon::create(['user_id' => null, 'code' => 'X2', 'discount_type' => 'percent', 'discount_value' => 5]);
        $restaurant = $this->restaurant();

        foreach ([$other, $platform] as $coupon) {
            $this->actingAs($restaurant)->delete(route('restaurant.coupons.destroy', $coupon))->assertForbidden();
            $this->actingAs($restaurant)->put(route('restaurant.coupons.update', $coupon), $this->couponForm())->assertForbidden();
        }

        $this->actingAs($restaurant)->get(route('restaurant.coupons.index'))
            ->assertInertia(fn (Assert $page) => $page->component('restaurant/coupons')->has('coupons', 0));
    }

    // ─── Admin ────────────────────────────────────────────────────────────────

    public function test_admin_creates_platform_coupons(): void
    {
        $this->actingAs($this->admin())->post(route('admin.coupons.store'), $this->couponForm())
            ->assertRedirect(route('admin.coupons.index'));

        $this->assertNull(Coupon::sole()->user_id);
    }

    public function test_only_admins_manage_platform_coupons_and_restaurant_terms(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('admin.coupons.store'), $this->couponForm())->assertForbidden();
        $this->actingAs($restaurant)
            ->patch(route('admin.restaurants.update', $restaurant), ['fee_tier' => 'founder', 'platform_pays_stripe_fees' => true])
            ->assertForbidden();

        $this->assertSame(0, Coupon::count());
        $this->assertSame('default', $restaurant->refresh()->feeTier());
        $this->assertFalse($restaurant->platform_pays_stripe_fees);
    }

    public function test_admin_sets_a_restaurants_tier_and_sponsors_its_stripe_fees(): void
    {
        $restaurant = $this->restaurant();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.restaurants.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/restaurants')
                ->has('restaurants.data', 1)
                ->where('restaurants.data.0.fee_tier', 'default')
                ->has('tiers', 2));

        $this->actingAs($admin)
            ->patch(route('admin.restaurants.update', $restaurant), ['fee_tier' => 'founder', 'platform_pays_stripe_fees' => true])
            ->assertSessionHasNoErrors();

        $restaurant->refresh();
        $this->assertSame('founder', $restaurant->feeTier());
        $this->assertSame(0, $restaurant->feeRateBps());
        $this->assertTrue($restaurant->platform_pays_stripe_fees);
    }

    public function test_admin_cannot_set_an_unknown_tier_or_tier_a_non_restaurant(): void
    {
        $restaurant = $this->restaurant();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('admin.restaurants.update', $restaurant), ['fee_tier' => 'vip', 'platform_pays_stripe_fees' => false])
            ->assertSessionHasErrors('fee_tier');

        $this->actingAs($admin)
            ->patch(route('admin.restaurants.update', $this->customer()), ['fee_tier' => 'founder', 'platform_pays_stripe_fees' => false])
            ->assertNotFound();
    }
}
