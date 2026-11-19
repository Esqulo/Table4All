<?php

namespace Tests\Feature\Restaurant;

use App\Enums\OnlinePaymentStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Coupon;
use App\Models\OnlinePayment;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Support\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePaymentGateway;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * Customers paying their table in the app (Stripe Connect, faked): amounts,
 * holds between customers, coupons, the platform's fee and what it owes back.
 */
class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private FakePaymentGateway $gateway;

    private User $restaurant;

    private RestaurantTable $table;

    /** Opened the table: its responsible. */
    private User $owner;

    /** Let in by the owner. */
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->restaurant = $this->restaurant();
        $this->restaurant->forceFill(['stripe_account_id' => 'acct_restaurant', 'stripe_charges_enabled' => true])->save();

        $this->table = $this->openTable($this->restaurant);
        // A R$ 50,00 bill.
        $this->billLine($this->table, $this->product($this->restaurant), 5, 10.00);

        $this->owner = $this->customer();
        $this->member = $this->customer();
        $this->actingAs($this->owner)->get($this->tableUrl())->assertOk();
        $this->actingAs($this->member)->get($this->tableUrl())->assertOk();
        $this->actingAs($this->owner)->post(route('customer.join-requests.approve', [$this->code(), $this->member->id]));
    }

    private function code(): string
    {
        return (string) $this->table->access_code;
    }

    private function tableUrl(): string
    {
        return route('customer.table', $this->code());
    }

    /** Back from Stripe the customer lands on the bill tab. */
    private function billUrl(): string
    {
        return route('customer.table', ['code' => $this->code(), 'aba' => 'conta']);
    }

    /** @param  array<string, mixed>  $data */
    private function pay(User $user, float $amount, array $data = []): TestResponse
    {
        return $this->actingAs($user)->post(route('customer.payments.store', $this->code()), [
            'amount' => $amount,
            ...$data,
        ]);
    }

    private function lastPayment(): OnlinePayment
    {
        return OnlinePayment::latest('id')->firstOrFail();
    }

    /** The customer pays on Stripe and comes back to the app. */
    private function completeOnStripe(User $user, ?OnlinePayment $payment = null): TestResponse
    {
        $payment ??= $this->lastPayment();
        $this->gateway->pay((string) $payment->checkout_session_id);

        return $this->actingAs($user)->get(route('customer.payments.return', $payment).'?result=success');
    }

    private function paidOnTable(): float
    {
        return (float) DB::table('table_payments')->where('restaurant_table_id', $this->table->id)->sum('amount');
    }

    private function coupon(?User $owner, array $attributes = []): Coupon
    {
        return Coupon::create([
            'user_id' => $owner?->id,
            'code' => 'DESCONTO',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'multiple_per_table' => false,
            ...$attributes,
        ]);
    }

    // ─── Paying ───────────────────────────────────────────────────────────────

    public function test_customer_is_sent_to_a_checkout_for_the_amount_they_chose(): void
    {
        $this->pay($this->member, 20)
            ->assertRedirect('https://checkout.stripe.test/'.$this->gateway->lastSessionId());

        $request = $this->gateway->checkouts[$this->gateway->lastSessionId()];
        $this->assertSame(2000, $request->chargeCents);
        // Default tier: 4.5% for the platform.
        $this->assertSame(90, $request->applicationFeeCents);
        $this->assertSame('acct_restaurant', $this->gateway->checkoutAccounts[$this->gateway->lastSessionId()]);

        $payment = $this->lastPayment();
        $this->assertSame(OnlinePaymentStatus::PENDING, $payment->status);
        $this->assertSame($this->member->id, $payment->user_id);
        // Nothing is on the bill until Stripe confirms it.
        $this->assertSame(0.0, $this->paidOnTable());
    }

    public function test_a_confirmed_payment_comes_off_the_bill_and_everyone_sees_who_paid(): void
    {
        $this->pay($this->member, 20);
        $this->completeOnStripe($this->member)->assertRedirect($this->billUrl());

        $this->assertSame(20.0, $this->paidOnTable());
        $this->assertSame(OnlinePaymentStatus::PAID, $this->lastPayment()->status);

        $this->actingAs($this->owner)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('paid', 20)
                ->where('remaining', 30)
                ->has('payments', 1)
                ->where('payments.0.method', 'online')
                ->where('payments.0.payer', $this->member->name)
                ->where('payments.0.by_staff', false));
    }

    public function test_pay_all_settles_the_whole_bill(): void
    {
        $this->pay($this->owner, 50);
        $this->completeOnStripe($this->owner);

        $this->actingAs($this->owner)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page->where('remaining', 0));
    }

    public function test_staff_payments_are_listed_without_naming_the_waiter(): void
    {
        $waiter = $this->waiterOf($this->restaurant);
        $this->actingAs($waiter)->post(route('restaurant.tables.add-payment', $this->table), ['method' => 'cash', 'amount' => 15]);

        $this->actingAs($this->member)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.0.method', 'cash')
                ->where('payments.0.payer', null)
                ->where('payments.0.by_staff', true));
    }

    public function test_paying_more_than_is_left_is_refused(): void
    {
        $this->pay($this->owner, 50.01)->assertSessionHasErrors('amount');
        $this->assertSame([], $this->gateway->checkouts);
    }

    public function test_an_amount_below_the_stripe_minimum_is_refused(): void
    {
        $this->pay($this->owner, 0.40)->assertSessionHasErrors('amount');
    }

    public function test_a_restaurant_without_a_financial_profile_takes_no_payments(): void
    {
        $this->restaurant->forceFill(['stripe_charges_enabled' => false])->save();

        $this->actingAs($this->owner)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page->where('onlinePayments.enabled', false));

        $this->pay($this->owner, 10)->assertSessionHasErrors('amount');
        $this->assertSame([], $this->gateway->checkouts);
    }

    public function test_customers_not_at_the_table_cannot_pay_it(): void
    {
        $stranger = $this->customer();
        $this->actingAs($stranger)->get($this->tableUrl()); // asks to join; not approved

        $this->pay($stranger, 10)->assertForbidden();
    }

    public function test_a_closed_table_cannot_be_paid(): void
    {
        $code = $this->code();
        $this->table->forceFill(['closed_at' => now(), 'access_code' => null])->save();

        $this->actingAs($this->owner)
            ->post(route('customer.payments.store', $code), ['amount' => 10])
            ->assertNotFound();
    }

    public function test_a_resent_request_reopens_the_same_checkout(): void
    {
        $this->pay($this->owner, 20, ['request_key' => 'k-1']);
        $first = $this->gateway->lastSessionId();

        $this->pay($this->owner, 20, ['request_key' => 'k-1'])
            ->assertRedirect('https://checkout.stripe.test/'.$first);

        $this->assertCount(1, $this->gateway->checkouts);
        $this->assertSame(1, OnlinePayment::count());
    }

    public function test_return_webhook_and_reconcile_record_a_payment_once(): void
    {
        $this->pay($this->owner, 20);
        $payment = $this->lastPayment();

        $this->completeOnStripe($this->owner, $payment);
        $this->actingAs($this->owner)->get(route('customer.payments.return', $payment).'?result=success');

        $this->gateway->nextWebhookEvent = [
            'type' => 'checkout.session.completed',
            'account' => 'acct_restaurant',
            'object' => ['id' => $payment->checkout_session_id],
        ];
        $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'valid'], '{}')->assertOk();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(20.0, $this->paidOnTable());
        $this->assertSame(1, DB::table('table_payments')->count());
    }

    public function test_the_webhook_confirms_a_payment_when_the_customer_never_comes_back(): void
    {
        $this->pay($this->owner, 20);
        $payment = $this->lastPayment();
        $this->gateway->pay((string) $payment->checkout_session_id);

        $this->gateway->nextWebhookEvent = [
            'type' => 'checkout.session.completed',
            'account' => 'acct_restaurant',
            'object' => ['id' => $payment->checkout_session_id],
        ];
        $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'valid'], '{}')->assertOk();

        $this->assertSame(20.0, $this->paidOnTable());
    }

    public function test_the_webhook_refuses_unsigned_events(): void
    {
        $this->pay($this->owner, 20);
        $payment = $this->lastPayment();
        $this->gateway->pay((string) $payment->checkout_session_id);
        $this->gateway->nextWebhookEvent = ['type' => 'checkout.session.completed', 'account' => null, 'object' => ['id' => $payment->checkout_session_id]];

        $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'forged'], '{}')
            ->assertStatus(400);

        $this->assertSame(0.0, $this->paidOnTable());
    }

    public function test_a_pix_waiting_for_confirmation_is_shown_and_recorded_once_paid(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();
        $this->gateway->awaitPix((string) $payment->checkout_session_id);

        $this->actingAs($this->member)->get(route('customer.payments.return', $payment).'?result=success');
        $this->assertSame(OnlinePaymentStatus::PROCESSING, $payment->refresh()->status);
        $this->assertSame(0.0, $this->paidOnTable());

        $this->actingAs($this->owner)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.0.status', 'processing')
                ->where('payments.0.payer', $this->member->name));

        // Its amount stays held: the owner can't pay the full balance meanwhile.
        $this->pay($this->owner, 50)->assertSessionHasErrors('amount');

        $this->gateway->pay((string) $payment->checkout_session_id);
        $payment->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(OnlinePaymentStatus::PAID, $payment->refresh()->status);
        $this->assertSame(20.0, $this->paidOnTable());
    }

    public function test_another_customers_open_checkout_holds_its_amount(): void
    {
        $this->pay($this->member, 40);

        $this->pay($this->owner, 10.01)->assertSessionHasErrors('amount');
        $this->pay($this->owner, 10)->assertRedirect();
    }

    public function test_starting_a_new_checkout_closes_the_customers_open_one(): void
    {
        $this->pay($this->owner, 40);
        $first = $this->lastPayment();

        // Their own open checkout doesn't hold the balance against them.
        $this->pay($this->owner, 50)->assertRedirect();

        $this->assertSame(OnlinePaymentStatus::CANCELED, $first->refresh()->status);
        $this->assertContains($first->checkout_session_id, $this->gateway->expired);
    }

    public function test_giving_up_on_stripe_frees_the_held_amount(): void
    {
        $this->pay($this->member, 50);
        $payment = $this->lastPayment();

        $this->actingAs($this->member)
            ->get(route('customer.payments.return', $payment).'?result=cancel')
            ->assertRedirect($this->billUrl());

        $this->assertSame(OnlinePaymentStatus::CANCELED, $payment->refresh()->status);
        $this->pay($this->owner, 50)->assertRedirect();
    }

    public function test_a_customer_resumes_or_drops_their_open_checkout(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();

        $this->actingAs($this->member)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('myCheckout.id', $payment->id)
                ->where('myCheckout.url', $payment->checkout_url));

        // Nobody else can drop it.
        $this->actingAs($this->owner)->post(route('customer.payments.cancel', $payment))->assertForbidden();

        $this->actingAs($this->member)->post(route('customer.payments.cancel', $payment))->assertRedirect($this->billUrl());
        $this->assertSame(OnlinePaymentStatus::CANCELED, $payment->refresh()->status);
    }

    public function test_a_checkout_paid_just_as_it_was_dropped_is_still_recorded(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();
        $this->gateway->pay((string) $payment->checkout_session_id);

        // Stripe refuses to expire a paid checkout; the app finds out it was paid.
        $this->actingAs($this->member)->post(route('customer.payments.cancel', $payment));

        $this->assertSame(OnlinePaymentStatus::PAID, $payment->refresh()->status);
        $this->assertSame(20.0, $this->paidOnTable());
    }

    public function test_a_stripe_outage_frees_the_hold_and_tells_the_customer(): void
    {
        $this->gateway->failCheckout = true;

        $this->pay($this->owner, 20)->assertSessionHasErrors('amount');

        $this->assertSame(OnlinePaymentStatus::CANCELED, $this->lastPayment()->status);
        $this->gateway->failCheckout = false;
        $this->pay($this->member, 50)->assertRedirect();
    }

    public function test_staff_cannot_register_an_online_payment_by_hand(): void
    {
        $this->actingAs($this->restaurant)
            ->post(route('restaurant.tables.add-payment', $this->table), ['method' => 'online', 'amount' => 10])
            ->assertSessionHasErrors('method');
    }

    // ─── Refunds (the bill was paid meanwhile) ────────────────────────────────

    private function waiterRegisters(float $amount): void
    {
        $this->actingAs($this->waiterOf($this->restaurant))
            ->post(route('restaurant.tables.add-payment', $this->table), ['method' => 'cash', 'amount' => $amount]);
    }

    public function test_the_part_of_a_payment_the_bill_no_longer_owed_is_refunded(): void
    {
        $this->pay($this->member, 50);
        $payment = $this->lastPayment();

        // While the customer is on Stripe, the waiter takes R$ 30,00 in cash.
        $this->waiterRegisters(30);
        $this->completeOnStripe($this->member, $payment)->assertRedirect($this->billUrl());

        $payment->refresh();
        $this->assertSame(OnlinePaymentStatus::PAID, $payment->status);
        $this->assertSame(2000, $payment->credited_cents);
        $this->assertSame(3000, $payment->refunded_cents);
        $this->assertSame([['account' => 'acct_restaurant', 'payment_intent' => 'pi_'.$payment->checkout_session_id, 'cents' => 3000, 'key' => $payment->stripeKey('refund')]], $this->gateway->refunds);
        $this->assertNotNull($payment->refund_id);

        // The bill is exactly paid, not overpaid.
        $this->assertSame(50.0, $this->paidOnTable());
    }

    public function test_a_payment_confirmed_after_the_bill_was_settled_is_refunded_in_full(): void
    {
        $this->coupon($this->restaurant, ['code' => 'SO1']);
        $this->pay($this->member, 50, ['coupon' => 'SO1']);
        $payment = $this->lastPayment();

        $this->waiterRegisters(50);
        $this->completeOnStripe($this->member, $payment);

        $payment->refresh();
        $this->assertSame(OnlinePaymentStatus::REFUNDED, $payment->status);
        $this->assertSame($payment->charge_cents, $this->gateway->refunds[0]['cents']);
        $this->assertSame(0, DB::table('table_payments')->where('online_payment_id', $payment->id)->count());
        $this->assertSame(50.0, $this->paidOnTable());

        // The coupon was never really used: after another round, it is accepted again.
        $this->billLine($this->table, $this->product($this->restaurant, ['name' => 'Porção']), 1, 20.00);
        $this->pay($this->owner, 20, ['coupon' => 'SO1'])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_the_coupon_covers_the_credited_part_first(): void
    {
        $this->coupon($this->restaurant, ['discount_type' => 'fixed', 'discount_value' => 5]);
        $this->pay($this->member, 50, ['coupon' => 'DESCONTO']); // charge R$ 45,00
        $payment = $this->lastPayment();

        $this->waiterRegisters(40);
        $this->completeOnStripe($this->member, $payment);

        // R$ 10,00 were still owed: R$ 5,00 coupon + R$ 5,00 card; R$ 40,00 back to the card.
        $payment->refresh();
        $this->assertSame(1000, $payment->credited_cents);
        $this->assertSame(500, $payment->discount_cents);
        $this->assertSame(4000, $payment->refunded_cents);
        // The platform's share follows what the restaurant kept: 4.5% of R$ 5,00.
        $this->assertSame(23, $payment->fee_cents);
        $this->assertEqualsCanonicalizing(
            [['cash', 40.0], ['online', 5.0], ['coupon', 5.0]],
            DB::table('table_payments')->get(['method', 'amount'])->map(fn ($r) => [$r->method, (float) $r->amount])->all(),
        );
    }

    public function test_a_failed_refund_is_retried_by_the_reconcile_command(): void
    {
        $this->pay($this->member, 50);
        $payment = $this->lastPayment();
        $this->waiterRegisters(50);

        $this->gateway->failRefund = true;
        $this->completeOnStripe($this->member, $payment);
        $this->assertNull($payment->refresh()->settled_at);

        $this->gateway->failRefund = false;
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertCount(1, $this->gateway->refunds);
        $this->assertNotNull($payment->refresh()->settled_at);
    }

    public function test_two_payments_confirmed_together_never_overpay_the_bill(): void
    {
        $this->pay($this->member, 30);
        $first = $this->lastPayment();
        $this->pay($this->owner, 20);
        $second = $this->lastPayment();

        // Meanwhile the waiter takes R$ 10,00: together they'd be R$ 60,00 on a R$ 50,00 bill.
        $this->waiterRegisters(10);
        $this->completeOnStripe($this->member, $first);
        $this->completeOnStripe($this->owner, $second);

        $this->assertSame(50.0, $this->paidOnTable());
        $this->assertSame(1000, $second->refresh()->refunded_cents);
        $this->assertSame(0, $first->refresh()->refunded_cents);
    }

    // ─── Fees ─────────────────────────────────────────────────────────────────

    public function test_founder_restaurants_pay_no_platform_fee(): void
    {
        $this->restaurant->forceFill(['fee_tier' => 'founder'])->save();

        $this->pay($this->owner, 20);

        $this->assertSame(0, $this->gateway->checkouts[$this->gateway->lastSessionId()]->applicationFeeCents);
        $this->assertSame('founder', $this->lastPayment()->fee_tier);
    }

    public function test_an_unknown_tier_falls_back_to_the_default_rate(): void
    {
        $this->restaurant->forceFill(['fee_tier' => 'gone'])->save();

        $this->pay($this->owner, 20);

        $this->assertSame(90, $this->gateway->checkouts[$this->gateway->lastSessionId()]->applicationFeeCents);
    }

    public function test_tier_rates_come_from_config(): void
    {
        config(['payments.tiers.default.fee_percent' => 3.0]);

        $this->pay($this->owner, 20);

        $this->assertSame(60, $this->gateway->checkouts[$this->gateway->lastSessionId()]->applicationFeeCents);
    }

    public function test_the_platform_covers_stripes_fee_for_sponsored_restaurants_out_of_its_commission(): void
    {
        $this->restaurant->forceFill(['platform_pays_stripe_fees' => true])->save();
        $this->gateway->processingFee = 60;

        $this->pay($this->owner, 20); // commission: 4.5% = R$ 0,90
        $payment = $this->lastPayment();
        $this->completeOnStripe($this->owner, $payment);

        $this->assertSame(
            [['account' => 'acct_restaurant', 'payment_intent' => 'pi_'.$payment->checkout_session_id, 'cents' => 60, 'key' => $payment->stripeKey('fee-refund')]],
            $this->gateway->commissionRefunds,
        );
        $this->assertSame(60, $payment->refresh()->fee_refund_cents);
        $this->assertNotNull($payment->settled_at);
    }

    public function test_covering_stripes_fee_never_costs_the_platform_more_than_its_commission(): void
    {
        $this->restaurant->forceFill(['platform_pays_stripe_fees' => true])->save();
        $this->gateway->processingFee = 139;

        $this->pay($this->owner, 20);
        $this->completeOnStripe($this->owner);

        // Stripe Brazil allows no free transfers: the commission (R$ 0,90) is all it can give back.
        $this->assertSame(90, $this->lastPayment()->fee_refund_cents);
        $this->assertSame(0, $this->lastPayment()->owed_cents);
    }

    public function test_a_sponsored_founder_gets_nothing_back_since_there_is_no_commission(): void
    {
        $this->restaurant->forceFill(['platform_pays_stripe_fees' => true, 'fee_tier' => 'founder'])->save();

        $this->pay($this->owner, 20);
        $this->completeOnStripe($this->owner);

        $this->assertSame([], $this->gateway->commissionRefunds);
        $this->assertSame(0, $this->lastPayment()->fee_refund_cents);
        $this->assertNotNull($this->lastPayment()->settled_at);
    }

    public function test_restaurants_pay_stripes_fee_by_default(): void
    {
        $this->pay($this->owner, 20);
        $this->completeOnStripe($this->owner);

        $this->assertSame([], $this->gateway->commissionRefunds);
        $this->assertNull($this->lastPayment()->fee_refund_cents);
        $this->assertNotNull($this->lastPayment()->settled_at);
    }

    public function test_a_fee_not_known_yet_is_covered_by_the_reconcile_command(): void
    {
        $this->restaurant->forceFill(['platform_pays_stripe_fees' => true])->save();
        $this->gateway->processingFee = null;

        $this->pay($this->owner, 20);
        $this->completeOnStripe($this->owner);
        $this->assertSame([], $this->gateway->commissionRefunds);
        $this->assertNull($this->lastPayment()->settled_at);

        $this->gateway->processingFee = 70;
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertCount(1, $this->gateway->commissionRefunds);
        $this->assertSame(70, $this->gateway->commissionRefunds[0]['cents']);
    }

    // ─── Coupons ──────────────────────────────────────────────────────────────

    public function test_a_restaurant_coupon_discounts_the_charge_and_the_bill_drops_by_the_full_amount(): void
    {
        // 10% capped at R$ 3,00.
        $this->coupon($this->restaurant, ['max_discount' => 3]);

        $this->pay($this->owner, 50, ['coupon' => 'desconto']);
        $request = $this->gateway->checkouts[$this->gateway->lastSessionId()];
        $this->assertSame(4700, $request->chargeCents);
        // The platform's share is on what the restaurant gets: 4.5% of R$ 47,00.
        $this->assertSame(212, $request->applicationFeeCents);

        $this->completeOnStripe($this->owner);

        $this->assertSame(50.0, $this->paidOnTable());
        $this->assertEqualsCanonicalizing(
            [['online', 47.0], ['coupon', 3.0]],
            DB::table('table_payments')->get(['method', 'amount'])->map(fn ($r) => [$r->method, (float) $r->amount])->all(),
        );
        $this->assertSame(0, $this->lastPayment()->owed_cents);
    }

    public function test_a_platform_coupon_is_paid_by_the_platform(): void
    {
        $this->coupon(null, ['code' => 'APP10', 'discount_type' => 'fixed', 'discount_value' => 10]);

        $this->pay($this->owner, 50, ['coupon' => 'APP10']);
        $request = $this->gateway->checkouts[$this->gateway->lastSessionId()];
        $this->assertSame(4000, $request->chargeCents);
        // Fee on the full R$ 50,00 (225), netted against the R$ 10,00 discount: nothing withheld...
        $this->assertSame(0, $request->applicationFeeCents);

        $this->completeOnStripe($this->owner);

        // ...and the rest of the discount is owed to the restaurant, paid outside Stripe.
        $this->assertSame(775, $this->lastPayment()->owed_cents);
        $this->assertNull($this->lastPayment()->owed_paid_at);
        $this->assertSame(50.0, $this->paidOnTable());
    }

    public function test_the_admin_pays_out_platform_coupons_and_marks_them_paid(): void
    {
        $this->coupon(null, ['code' => 'APP10', 'discount_type' => 'fixed', 'discount_value' => 10]);
        $this->pay($this->owner, 50, ['coupon' => 'APP10']);
        $this->completeOnStripe($this->owner);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.payouts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/payouts')
                ->has('payouts', 1)
                ->where('payouts.0.restaurant_id', $this->restaurant->id)
                ->where('payouts.0.amount', 7.75)
                ->where('payouts.0.payments', 1));

        // A payment confirmed after the admin looked isn't swept into the payout.
        $listed = $this->lastPayment()->id;
        $later = OnlinePayment::create([...$this->lastPayment()->only([
            'restaurant_table_id', 'user_id', 'restaurant_id', 'stripe_account_id', 'amount_cents', 'discount_cents',
            'charge_cents', 'fee_tier', 'fee_rate_bps', 'fee_cents', 'application_fee_cents',
        ]), 'status' => OnlinePaymentStatus::PAID]);
        $later->forceFill(['owed_cents' => 500])->save();

        $this->actingAs($this->restaurant)
            ->post(route('admin.payouts.store'), ['restaurant_id' => $this->restaurant->id, 'up_to_id' => $listed])
            ->assertForbidden();
        $this->actingAs($admin)
            ->post(route('admin.payouts.store'), ['restaurant_id' => $this->restaurant->id, 'up_to_id' => $listed])
            ->assertRedirect(route('admin.payouts.index'));

        $this->assertNotNull(OnlinePayment::find($listed)?->owed_paid_at);
        $this->assertNull($later->refresh()->owed_paid_at);

        // The restaurant sees it as received.
        $this->actingAs($this->restaurant)->get(route('restaurant.finance.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.1.owed', 7.75)
                ->where('payments.1.owed_paid', true));
    }

    public function test_a_small_platform_coupon_just_lowers_the_fee(): void
    {
        $this->coupon(null, ['code' => 'APP1', 'discount_type' => 'fixed', 'discount_value' => 1]);

        $this->pay($this->owner, 50, ['coupon' => 'APP1']);

        $this->assertSame(125, $this->gateway->checkouts[$this->gateway->lastSessionId()]->applicationFeeCents);
        $this->completeOnStripe($this->owner);
        $this->assertSame(0, $this->lastPayment()->owed_cents);
    }

    public function test_the_restaurants_own_code_wins_over_a_platform_code(): void
    {
        $this->coupon(null, ['code' => 'MESA', 'discount_type' => 'fixed', 'discount_value' => 10]);
        $this->coupon($this->restaurant, ['code' => 'MESA', 'discount_type' => 'fixed', 'discount_value' => 2]);

        $this->pay($this->owner, 50, ['coupon' => 'MESA']);

        $this->assertSame(4800, $this->gateway->checkouts[$this->gateway->lastSessionId()]->chargeCents);
        $this->assertFalse($this->lastPayment()->coupon_by_platform);
    }

    public function test_another_restaurants_coupon_is_not_accepted(): void
    {
        $this->coupon($this->restaurant());

        $this->pay($this->owner, 50, ['coupon' => 'DESCONTO'])->assertSessionHasErrors('coupon');
    }

    public function test_expired_and_deleted_coupons_are_not_accepted(): void
    {
        $this->coupon($this->restaurant, ['code' => 'VELHO', 'expires_at' => now()->subMinute()]);
        $this->coupon($this->restaurant, ['code' => 'APAGADO'])->delete();

        $this->pay($this->owner, 50, ['coupon' => 'VELHO'])->assertSessionHasErrors('coupon');
        $this->pay($this->owner, 50, ['coupon' => 'APAGADO'])->assertSessionHasErrors('coupon');
    }

    public function test_a_coupon_never_takes_the_charge_below_the_minimum(): void
    {
        $this->coupon($this->restaurant, ['discount_type' => 'fixed', 'discount_value' => 30]);

        $this->pay($this->owner, 20, ['coupon' => 'DESCONTO']);

        $this->assertSame(50, $this->gateway->checkouts[$this->gateway->lastSessionId()]->chargeCents);
        $this->assertSame(1950, $this->lastPayment()->discount_cents);
    }

    public function test_after_a_single_use_coupon_no_other_coupon_is_accepted_at_the_table(): void
    {
        $this->coupon($this->restaurant, ['code' => 'SO1']);
        $this->coupon($this->restaurant, ['code' => 'VARIOS', 'multiple_per_table' => true]);

        $this->pay($this->owner, 20, ['coupon' => 'SO1']);
        $this->completeOnStripe($this->owner);

        $this->pay($this->member, 10, ['coupon' => 'VARIOS'])->assertSessionHasErrors('coupon');
        // Without a coupon they still pay.
        $this->pay($this->member, 10)->assertRedirect();
    }

    public function test_a_single_use_coupon_is_refused_where_another_coupon_was_used(): void
    {
        $this->coupon($this->restaurant, ['code' => 'VARIOS', 'multiple_per_table' => true]);
        $this->coupon($this->restaurant, ['code' => 'SO1']);

        $this->pay($this->owner, 20, ['coupon' => 'VARIOS']);
        $this->completeOnStripe($this->owner);

        $this->pay($this->member, 10, ['coupon' => 'SO1'])->assertSessionHasErrors('coupon');
    }

    public function test_combinable_coupons_allow_one_per_customer(): void
    {
        $this->coupon($this->restaurant, ['code' => 'VARIOS', 'multiple_per_table' => true]);
        $this->coupon($this->restaurant, ['code' => 'OUTRO', 'multiple_per_table' => true]);

        $this->pay($this->owner, 20, ['coupon' => 'VARIOS']);
        $this->completeOnStripe($this->owner);

        $this->pay($this->member, 10, ['coupon' => 'VARIOS'])->assertRedirect();
        $this->completeOnStripe($this->member);

        // One coupon per customer: the owner can't use another one.
        $this->pay($this->owner, 5, ['coupon' => 'OUTRO'])->assertSessionHasErrors('coupon');
    }

    public function test_a_coupon_in_an_open_checkout_counts_as_used(): void
    {
        $this->coupon($this->restaurant, ['code' => 'SO1']);
        $this->coupon($this->restaurant, ['code' => 'SO2']);

        $this->pay($this->owner, 20, ['coupon' => 'SO1']);

        $this->pay($this->member, 10, ['coupon' => 'SO2'])->assertSessionHasErrors('coupon');
    }

    public function test_a_coupon_from_a_dropped_checkout_can_be_used_again(): void
    {
        $this->coupon($this->restaurant, ['code' => 'SO1']);

        $this->pay($this->owner, 20, ['coupon' => 'SO1']);
        $this->actingAs($this->owner)->post(route('customer.payments.cancel', $this->lastPayment()));

        $this->pay($this->member, 10, ['coupon' => 'SO1'])->assertRedirect();
    }

    public function test_the_payment_form_previews_a_coupon(): void
    {
        $this->coupon($this->restaurant, ['discount_type' => 'fixed', 'discount_value' => 5]);

        $this->actingAs($this->owner)
            ->get($this->tableUrl().'?coupon=desconto&amount=30.00', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'customer/show',
                'X-Inertia-Partial-Data' => 'couponQuote',
            ])
            ->assertJsonPath('props.couponQuote.valid', true)
            ->assertJsonPath('props.couponQuote.discount', 5)
            ->assertJsonPath('props.couponQuote.charge', 25);

        $this->actingAs($this->owner)
            ->get($this->tableUrl().'?coupon=NOPE&amount=30.00', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'customer/show',
                'X-Inertia-Partial-Data' => 'couponQuote',
            ])
            ->assertJsonPath('props.couponQuote.valid', false);
    }
}
