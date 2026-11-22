<?php

namespace Tests\Feature\Restaurant;

use App\Enums\OnlinePaymentStatus;
use App\Enums\PaymentProvider;
use App\Models\OnlinePayment;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Support\Payments\MercadoPagoGateway;
use App\Support\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePaymentGateway;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * Restaurants receiving in-app payments on Mercado Pago: linking the account
 * (OAuth), Checkout Pro with the platform's commission as marketplace_fee,
 * confirmation by return, webhook and reconcile, refunds, sponsored fees,
 * and choosing between Mercado Pago and Stripe. Mercado Pago's API is
 * simulated with Http::fake, so the real gateway runs.
 */
class MercadoPagoPaymentTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private const SELLER = '777001';

    private const WEBHOOK_SECRET = 'mp-webhook-secret';

    private FakePaymentGateway $stripe;

    private User $restaurant;

    private RestaurantTable $table;

    private User $owner;

    private User $member;

    /** @var array<string, array<string, mixed>> preferences created, by id */
    private array $preferences = [];

    /** @var list<array<string, mixed>> payments on the seller's account */
    private array $payments = [];

    /** @var list<array{payment: string, amount: float, key: string|null}> */
    private array $refunds = [];

    /** @var list<array<string, mixed>> requests sent to /oauth/token */
    private array $tokenRequests = [];

    /** @var list<string> bearer tokens used on the seller's account */
    private array $tokensUsed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.mercadopago.client_id' => 'mp-client',
            'services.mercadopago.client_secret' => 'mp-secret',
            'services.mercadopago.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $this->app->forgetInstance(MercadoPagoGateway::class);

        $this->stripe = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->stripe);
        $this->fakeMercadoPago();

        $this->restaurant = $this->restaurant();
        $this->linkMercadoPago($this->restaurant);

        $this->table = $this->openTable($this->restaurant);
        // A R$ 50,00 bill.
        $this->billLine($this->table, $this->product($this->restaurant), 5, 10.00);

        $this->owner = $this->customer();
        $this->member = $this->customer();
        $this->actingAs($this->owner)->get($this->tableUrl())->assertOk();
        $this->actingAs($this->member)->get($this->tableUrl())->assertOk();
        $this->actingAs($this->owner)->post(route('customer.join-requests.approve', [$this->code(), $this->member->id]));
    }

    // ─── Simulated Mercado Pago ───────────────────────────────────────────────

    private function fakeMercadoPago(): void
    {
        Http::fake(function (HttpRequest $request) {
            if (parse_url($request->url(), PHP_URL_HOST) !== 'api.mercadopago.com') {
                // Other outside services (the geocoder) aren't part of this.
                return Http::response([], 404);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $bearer = str_replace('Bearer ', '', (string) ($request->header('Authorization')[0] ?? ''));

            if ($path === '/oauth/token') {
                $this->tokenRequests[] = $request->data();

                return $request->data()['grant_type'] === 'refresh_token'
                    ? Http::response(['access_token' => 'APP_USR-renewed', 'refresh_token' => 'TG-renewed', 'user_id' => (int) self::SELLER, 'expires_in' => 15552000])
                    : Http::response(['access_token' => 'APP_USR-seller', 'refresh_token' => 'TG-refresh', 'user_id' => (int) self::SELLER, 'expires_in' => 15552000, 'public_key' => 'APP_USR-pk']);
            }

            $this->tokensUsed[] = $bearer;

            if ($method === 'POST' && $path === '/checkout/preferences') {
                $id = 'pref-'.(count($this->preferences) + 1);
                $this->preferences[$id] = [...$request->data(), 'idempotency_key' => $request->header('X-Idempotency-Key')[0] ?? null];

                return Http::response(['id' => $id, 'init_point' => 'https://www.mercadopago.test/checkout?pref_id='.$id], 201);
            }

            if (preg_match('#^/checkout/preferences/(.+)$#', $path, $m)) {
                if (! isset($this->preferences[$m[1]])) {
                    return Http::response(['message' => 'not found'], 404);
                }

                if ($method === 'PUT') {
                    $this->preferences[$m[1]] = [...$this->preferences[$m[1]], ...$request->data()];
                }

                return Http::response(['id' => $m[1], ...$this->preferences[$m[1]]]);
            }

            if ($path === '/v1/payments/search') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['results' => array_values(array_filter(
                    array_reverse($this->payments),
                    fn (array $p) => $p['external_reference'] === ($query['external_reference'] ?? null),
                ))]);
            }

            if (preg_match('#^/v1/payments/(\d+)/refunds$#', $path, $m)) {
                $this->refunds[] = ['payment' => $m[1], 'amount' => (float) $request->data()['amount'], 'key' => $request->header('X-Idempotency-Key')[0] ?? null];

                return Http::response(['id' => 9000 + count($this->refunds)], 201);
            }

            if (preg_match('#^/v1/payments/(\d+)$#', $path, $m)) {
                foreach ($this->payments as $payment) {
                    if ((string) $payment['id'] === $m[1]) {
                        return Http::response($payment);
                    }
                }

                return Http::response(['message' => 'not found'], 404);
            }

            return Http::response(['message' => 'unexpected '.$method.' '.$path], 500);
        });
    }

    /** The customer pays the preference on Mercado Pago (approved card, or a Pix still to be paid). */
    private function payOnMercadoPago(OnlinePayment $payment, string $status = 'approved', float $mercadoPagoFee = 1.00): int
    {
        $id = 5000 + count($this->payments) + 1;
        $this->payments[] = [
            'id' => $id,
            'status' => $status,
            'external_reference' => $payment->reference,
            'transaction_amount' => $payment->charge_cents / 100,
            'fee_details' => [
                ['type' => 'mercadopago_fee', 'amount' => $mercadoPagoFee, 'fee_payer' => 'collector'],
                ['type' => 'application_fee', 'amount' => $payment->application_fee_cents / 100, 'fee_payer' => 'collector'],
            ],
        ];

        return $id;
    }

    private function setPaymentStatus(int $id, string $status): void
    {
        foreach ($this->payments as $i => $payment) {
            if ($payment['id'] === $id) {
                $this->payments[$i]['status'] = $status;
            }
        }
    }

    private function linkMercadoPago(User $restaurant): void
    {
        $restaurant->forceFill([
            'mercadopago_user_id' => self::SELLER,
            'mercadopago_access_token' => 'APP_USR-seller',
            'mercadopago_refresh_token' => 'TG-refresh',
            'mercadopago_token_expires_at' => now()->addDays(150),
            'payment_provider' => PaymentProvider::MERCADO_PAGO,
        ])->save();
    }

    /** @param  array<string, mixed>  $body */
    private function webhook(array $body, ?string $signature = null, string $requestId = 'req-1'): TestResponse
    {
        $dataId = (string) ($body['data']['id'] ?? '');
        $ts = (string) now()->getTimestamp();
        $signature ??= 'ts='.$ts.',v1='.hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            route('mercadopago.webhook').'?data.id='.$dataId.'&type='.($body['type'] ?? ''),
            [],
            [],
            [],
            ['HTTP_X_SIGNATURE' => $signature, 'HTTP_X_REQUEST_ID' => $requestId, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    private function code(): string
    {
        return (string) $this->table->access_code;
    }

    private function tableUrl(): string
    {
        return route('customer.table', $this->code());
    }

    private function pay(User $user, float $amount): TestResponse
    {
        return $this->actingAs($user)->post(route('customer.payments.store', $this->code()), ['amount' => $amount]);
    }

    private function lastPayment(): OnlinePayment
    {
        return OnlinePayment::latest('id')->firstOrFail();
    }

    private function paidOnTable(): float
    {
        return (float) DB::table('table_payments')->where('restaurant_table_id', $this->table->id)->sum('amount');
    }

    // ─── Linking the account ──────────────────────────────────────────────────

    public function test_restaurant_links_its_mercado_pago_account(): void
    {
        $restaurant = $this->restaurant();

        $response = $this->actingAs($restaurant)->post(route('restaurant.finance.mercadopago.connect'));
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://auth.mercadopago.com/authorization?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('mp-client', $query['client_id']);
        $this->assertSame(route('restaurant.finance.mercadopago.return'), $query['redirect_uri']);

        $this->actingAs($restaurant)
            ->get(route('restaurant.finance.mercadopago.return', ['code' => 'TG-code', 'state' => $query['state']]))
            ->assertRedirect(route('restaurant.finance.show'));

        $restaurant->refresh();
        $this->assertSame(self::SELLER, $restaurant->mercadopago_user_id);
        $this->assertSame('APP_USR-seller', $restaurant->mercadopago_access_token);
        $this->assertSame(PaymentProvider::MERCADO_PAGO, $restaurant->onlinePaymentProvider());
        $this->assertSame('authorization_code', $this->tokenRequests[0]['grant_type']);
        $this->assertSame('TG-code', $this->tokenRequests[0]['code']);

        // Tokens are stored encrypted.
        $raw = DB::table('users')->where('id', $restaurant->id)->value('mercadopago_access_token');
        $this->assertNotSame('APP_USR-seller', $raw);
    }

    public function test_a_return_without_the_state_this_session_started_is_refused(): void
    {
        $restaurant = $this->restaurant();
        $this->actingAs($restaurant)->post(route('restaurant.finance.mercadopago.connect'));

        $this->actingAs($restaurant)
            ->get(route('restaurant.finance.mercadopago.return', ['code' => 'TG-code', 'state' => 'forged']))
            ->assertRedirect(route('restaurant.finance.show'));

        $this->assertNull($restaurant->refresh()->mercadopago_user_id);
        $this->assertSame([], $this->tokenRequests);
    }

    public function test_without_the_platforms_credentials_mercado_pago_is_not_offered(): void
    {
        config(['services.mercadopago.client_id' => null]);
        $this->app->forgetInstance(MercadoPagoGateway::class);
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->get(route('restaurant.finance.show'))
            ->assertInertia(fn (Assert $page) => $page->where('mercadoPago.available', false));
        $this->actingAs($restaurant)->post(route('restaurant.finance.mercadopago.connect'))
            ->assertRedirect(route('restaurant.finance.show'));
    }

    public function test_waiters_cannot_link_or_unlink_mercado_pago(): void
    {
        $waiter = $this->waiterOf($this->restaurant);

        $this->actingAs($waiter)->post(route('restaurant.finance.mercadopago.connect'))->assertRedirect(route('dashboard'));
        $this->actingAs($waiter)->delete(route('restaurant.finance.mercadopago.disconnect'))->assertRedirect(route('dashboard'));
        $this->actingAs($waiter)->put(route('restaurant.finance.provider'), ['provider' => 'stripe'])->assertRedirect(route('dashboard'));
        $this->assertSame([], $this->tokenRequests);

        $this->assertSame(self::SELLER, $this->restaurant->refresh()->mercadopago_user_id);
    }

    public function test_mercado_pago_tokens_are_not_shared_with_the_browser(): void
    {
        $this->actingAs($this->restaurant)->get(route('restaurant.finance.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('auth.user.mercadopago_access_token')
                ->missing('auth.user.mercadopago_refresh_token')
                ->missing('auth.user.mercadopago_user_id')
                ->where('mercadoPago.connected', true)
                ->where('provider', 'mercadopago'));
    }

    // ─── Paying ───────────────────────────────────────────────────────────────

    public function test_customer_is_sent_to_checkout_pro_with_the_platforms_commission(): void
    {
        $this->pay($this->member, 20)
            ->assertRedirect('https://www.mercadopago.test/checkout?pref_id=pref-1');

        $payment = $this->lastPayment();
        $this->assertSame(PaymentProvider::MERCADO_PAGO, $payment->provider);
        $this->assertSame(self::SELLER, $payment->account_id);
        $this->assertSame('pref-1', $payment->checkout_session_id);

        $preference = $this->preferences['pref-1'];
        $this->assertEqualsWithDelta(20.0, $preference['items'][0]['unit_price'], 0.001);
        // Default tier: 4.5% for the platform, as on Stripe.
        $this->assertEqualsWithDelta(0.90, $preference['marketplace_fee'], 0.001);
        $this->assertSame($payment->reference, $preference['external_reference']);
        $this->assertSame($this->member->email, $preference['payer']['email']);
        $this->assertSame($payment->idempotencyKey('checkout'), $preference['idempotency_key']);
        $this->assertSame(['APP_USR-seller'], array_unique($this->tokensUsed));
        // Stripe isn't involved.
        $this->assertSame([], $this->stripe->checkouts);
        $this->assertSame(0.0, $this->paidOnTable());
    }

    public function test_the_customer_page_says_mercado_pago_processes_the_payment(): void
    {
        $this->actingAs($this->member)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('onlinePayments.enabled', true)
                ->where('onlinePayments.provider', 'Mercado Pago'));
    }

    public function test_an_approved_payment_comes_off_the_bill_once(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();
        $mpId = $this->payOnMercadoPago($payment);

        $this->actingAs($this->member)
            ->get(route('customer.payments.return', $payment).'?result=success&payment_id='.$mpId.'&status=approved')
            ->assertRedirect(route('customer.table', ['code' => $this->code(), 'aba' => 'conta']));

        $payment->refresh();
        $this->assertSame(OnlinePaymentStatus::PAID, $payment->status);
        $this->assertSame((string) $mpId, $payment->payment_intent_id);
        $this->assertSame(20.0, $this->paidOnTable());

        // Webhook and reconcile afterwards change nothing.
        $this->webhook(['type' => 'payment', 'action' => 'payment.updated', 'user_id' => (int) self::SELLER, 'data' => ['id' => (string) $mpId]])->assertOk();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(20.0, $this->paidOnTable());
        $this->assertNotNull($payment->refresh()->settled_at);
    }

    public function test_the_webhook_confirms_a_payment_when_the_customer_never_comes_back(): void
    {
        $this->pay($this->member, 20);
        $mpId = $this->payOnMercadoPago($this->lastPayment());

        $this->webhook(['type' => 'payment', 'action' => 'payment.created', 'user_id' => (int) self::SELLER, 'data' => ['id' => (string) $mpId]])->assertOk();

        $this->assertSame(OnlinePaymentStatus::PAID, $this->lastPayment()->status);
        $this->assertSame(20.0, $this->paidOnTable());
    }

    public function test_the_webhook_refuses_unsigned_or_forged_notifications(): void
    {
        $this->pay($this->member, 20);
        $mpId = $this->payOnMercadoPago($this->lastPayment());
        $body = ['type' => 'payment', 'user_id' => (int) self::SELLER, 'data' => ['id' => (string) $mpId]];

        $this->webhook($body, 'ts=1,v1=forged')->assertStatus(400);
        $this->webhook($body, '')->assertStatus(400);

        $this->assertSame(OnlinePaymentStatus::PENDING, $this->lastPayment()->status);
    }

    public function test_a_notification_from_another_account_does_not_touch_the_payment(): void
    {
        $this->pay($this->member, 20);
        $mpId = $this->payOnMercadoPago($this->lastPayment());

        // Another restaurant linked to a different Mercado Pago account, naming this payment.
        $other = $this->restaurant();
        $other->forceFill(['mercadopago_user_id' => '999', 'mercadopago_access_token' => 'APP_USR-other', 'mercadopago_token_expires_at' => now()->addDays(100)])->save();

        $this->webhook(['type' => 'payment', 'user_id' => 999, 'data' => ['id' => (string) $mpId]])->assertOk();

        $this->assertSame(OnlinePaymentStatus::PENDING, $this->lastPayment()->status);
    }

    public function test_a_pix_waiting_to_be_paid_holds_the_amount_until_approved(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();
        $mpId = $this->payOnMercadoPago($payment, 'pending');

        $this->actingAs($this->member)->get(route('customer.payments.return', $payment).'?result=success');
        $this->assertSame(OnlinePaymentStatus::PROCESSING, $payment->refresh()->status);
        $this->assertSame(0.0, $this->paidOnTable());

        // Another customer can only pay what isn't held.
        $this->pay($this->owner, 40)->assertSessionHasErrors('amount');

        $this->setPaymentStatus($mpId, 'approved');
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(OnlinePaymentStatus::PAID, $payment->refresh()->status);
        $this->assertSame(20.0, $this->paidOnTable());
    }

    public function test_a_refused_card_leaves_the_checkout_open_until_it_expires(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();
        $this->payOnMercadoPago($payment, 'rejected');

        $this->artisan('payments:reconcile');
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile');
        $this->assertSame(OnlinePaymentStatus::PENDING, $payment->refresh()->status);

        $this->travel(40)->minutes();
        $this->artisan('payments:reconcile');
        $this->assertSame(OnlinePaymentStatus::CANCELED, $payment->refresh()->status);
    }

    public function test_giving_up_closes_the_preference_and_frees_the_hold(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();

        $this->actingAs($this->member)->get(route('customer.payments.return', $payment).'?result=cancel');

        $this->assertSame(OnlinePaymentStatus::CANCELED, $payment->refresh()->status);
        $this->assertArrayHasKey('expiration_date_to', $this->preferences['pref-1']);
        $this->pay($this->owner, 50)->assertRedirect();
    }

    public function test_a_checkout_paid_just_as_it_was_dropped_is_still_recorded(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();
        $this->payOnMercadoPago($payment);

        $this->actingAs($this->member)->post(route('customer.payments.cancel', $payment));

        $this->assertSame(OnlinePaymentStatus::PAID, $payment->refresh()->status);
        $this->assertSame(20.0, $this->paidOnTable());
    }

    public function test_the_part_the_bill_no_longer_owed_is_refunded_on_mercado_pago(): void
    {
        $this->pay($this->member, 50);
        $payment = $this->lastPayment();
        $mpId = $this->payOnMercadoPago($payment);

        // Meanwhile a waiter took R$ 30,00 in cash.
        $this->actingAs($this->waiterOf($this->restaurant))
            ->post(route('restaurant.tables.add-payment', $this->table), ['method' => 'cash', 'amount' => 30]);

        $this->actingAs($this->member)->get(route('customer.payments.return', $payment).'?result=success');

        $payment->refresh();
        $this->assertSame(2000, $payment->credited_cents);
        $this->assertSame(3000, $payment->refunded_cents);
        $this->assertCount(1, $this->refunds);
        $this->assertSame((string) $mpId, $this->refunds[0]['payment']);
        $this->assertEqualsWithDelta(30.0, $this->refunds[0]['amount'], 0.001);
        $this->assertSame($payment->idempotencyKey('refund'), $this->refunds[0]['key']);
        $this->assertNotNull($payment->refund_id);
        $this->assertSame(50.0, $this->paidOnTable());
    }

    public function test_a_sponsored_restaurants_fee_is_owed_outside_mercado_pago_up_to_the_commission(): void
    {
        $this->restaurant->forceFill(['platform_pays_stripe_fees' => true])->save();

        // R$ 20,00: commission R$ 0,90; Mercado Pago's fee R$ 1,00 → the platform covers R$ 0,90.
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();
        $this->payOnMercadoPago($payment, 'approved', 1.00);
        $this->actingAs($this->member)->get(route('customer.payments.return', $payment).'?result=success');

        $payment->refresh();
        $this->assertSame(100, $payment->processing_fee_cents);
        $this->assertSame(90, $payment->fee_refund_cents);
        $this->assertNull($payment->fee_refund_id);
        $this->assertSame(90, $payment->owed_cents);
        $this->assertNotNull($payment->settled_at);
        $this->assertSame([], $this->refunds);

        $this->actingAs($this->admin())->get(route('admin.payouts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payouts.0.restaurant_id', $this->restaurant->id)
                ->where('payouts.0.amount', 0.9)
                ->where('payouts.0.payments_connected', true));
    }

    public function test_restaurants_pay_mercado_pagos_fee_by_default(): void
    {
        $this->pay($this->member, 20);
        $payment = $this->lastPayment();
        $this->payOnMercadoPago($payment);
        $this->actingAs($this->member)->get(route('customer.payments.return', $payment).'?result=success');

        $payment->refresh();
        $this->assertNull($payment->fee_refund_cents);
        $this->assertSame(0, $payment->owed_cents);
    }

    public function test_a_token_about_to_expire_is_renewed_before_use(): void
    {
        $this->restaurant->forceFill(['mercadopago_token_expires_at' => now()->addDays(5)])->save();

        $this->pay($this->member, 20)->assertRedirect();

        $this->assertSame('refresh_token', $this->tokenRequests[0]['grant_type']);
        $this->assertSame('TG-refresh', $this->tokenRequests[0]['refresh_token']);
        $this->assertSame('APP_USR-renewed', end($this->tokensUsed));
        $this->restaurant->refresh();
        $this->assertSame('APP_USR-renewed', $this->restaurant->mercadopago_access_token);
        $this->assertTrue($this->restaurant->mercadopago_token_expires_at->gt(now()->addDays(100)));
    }

    // ─── Choosing between Stripe and Mercado Pago ─────────────────────────────

    public function test_a_restaurant_with_both_accounts_chooses_where_payments_go(): void
    {
        $this->restaurant->forceFill(['stripe_account_id' => 'acct_restaurant', 'stripe_charges_enabled' => true])->save();

        $this->actingAs($this->restaurant)->put(route('restaurant.finance.provider'), ['provider' => 'stripe'])
            ->assertRedirect(route('restaurant.finance.show'));

        $this->pay($this->member, 20)->assertRedirect('https://checkout.stripe.test/'.$this->stripe->lastSessionId());
        $this->assertSame(PaymentProvider::STRIPE, $this->lastPayment()->provider);
        $this->assertSame('acct_restaurant', $this->lastPayment()->account_id);
        $this->assertSame([], $this->preferences);

        // Back to Mercado Pago: the open Stripe checkout still settles on Stripe.
        $stripePayment = $this->lastPayment();
        $this->actingAs($this->restaurant)->put(route('restaurant.finance.provider'), ['provider' => 'mercadopago']);
        $this->stripe->pay((string) $stripePayment->checkout_session_id);
        $this->actingAs($this->member)->get(route('customer.payments.return', $stripePayment).'?result=success');

        $this->assertSame(OnlinePaymentStatus::PAID, $stripePayment->refresh()->status);
        $this->pay($this->owner, 10)->assertRedirect('https://www.mercadopago.test/checkout?pref_id=pref-1');
    }

    public function test_an_account_that_cannot_take_payments_cannot_be_chosen(): void
    {
        $this->restaurant->forceFill(['stripe_account_id' => 'acct_restaurant', 'stripe_charges_enabled' => false])->save();

        $this->actingAs($this->restaurant)->put(route('restaurant.finance.provider'), ['provider' => 'stripe'])
            ->assertSessionHasErrors('provider');
        $this->actingAs($this->restaurant)->put(route('restaurant.finance.provider'), ['provider' => 'paypal'])
            ->assertSessionHasErrors('provider');

        $this->assertSame(PaymentProvider::MERCADO_PAGO, $this->restaurant->refresh()->payment_provider);
    }

    public function test_unlinking_waits_for_payments_in_flight_then_falls_back_to_stripe(): void
    {
        $this->restaurant->forceFill(['stripe_account_id' => 'acct_restaurant', 'stripe_charges_enabled' => true])->save();
        $this->pay($this->member, 20);

        $this->actingAs($this->restaurant)->delete(route('restaurant.finance.mercadopago.disconnect'));
        $this->assertSame(self::SELLER, $this->restaurant->refresh()->mercadopago_user_id);

        $this->actingAs($this->member)->post(route('customer.payments.cancel', $this->lastPayment()));
        $this->actingAs($this->restaurant)->delete(route('restaurant.finance.mercadopago.disconnect'));

        $this->restaurant->refresh();
        $this->assertNull($this->restaurant->mercadopago_user_id);
        $this->assertNull($this->restaurant->mercadopago_access_token);
        $this->assertSame(PaymentProvider::STRIPE, $this->restaurant->onlinePaymentProvider());
    }

    public function test_unlinking_on_mercado_pagos_side_turns_its_payments_off(): void
    {
        $this->webhook(['type' => 'mp-connect', 'action' => 'application.deauthorized', 'user_id' => (int) self::SELLER, 'data' => ['id' => '1']])->assertOk();

        $this->restaurant->refresh();
        $this->assertNull($this->restaurant->mercadopago_access_token);
        $this->assertFalse($this->restaurant->acceptsOnlinePayments());
        $this->pay($this->member, 20)->assertSessionHasErrors('amount');
    }

    public function test_closing_a_restaurant_forgets_its_mercado_pago_tokens(): void
    {
        $restaurant = $this->restaurant();
        $this->linkMercadoPago($restaurant);

        $this->artisan('restaurants:close', ['id' => $restaurant->id, '--force' => true]);

        $restaurant->refresh();
        $this->assertNull($restaurant->mercadopago_access_token);
        $this->assertNull($restaurant->mercadopago_refresh_token);
    }
}
