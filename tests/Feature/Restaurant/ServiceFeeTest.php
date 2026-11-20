<?php

namespace Tests\Feature\Restaurant;

use App\Models\RestaurantTable;
use App\Models\User;
use App\Support\Payments\PaymentGateway;
use App\Support\ServiceFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePaymentGateway;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** The service fee: the venue setting, each table's copy of it, waiving it and what the bill owes. */
class ServiceFeeTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->restaurant = $this->restaurant();
    }

    /** @param  array<string, mixed>  $data */
    private function configure(array $data, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->restaurant)
            ->put(route('restaurant.establishment.service-fee.update'), [
                'service_fee_type' => null,
                ...$data,
            ]);
    }

    private function percent(float $value, ?float $cap = null): void
    {
        $this->configure(['service_fee_type' => 'percent', 'service_fee_value' => $value, 'service_fee_cap' => $cap])
            ->assertSessionHasNoErrors();
    }

    private function close(RestaurantTable $table, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->restaurant)->patch(route('restaurant.tables.close', $table));
    }

    private function pay(RestaurantTable $table, float $amount): void
    {
        $this->actingAs($this->restaurant)
            ->post(route('restaurant.tables.add-payment', $table), ['method' => 'cash', 'amount' => $amount])
            ->assertSessionHasNoErrors();
    }

    private function isClosed(RestaurantTable $table): bool
    {
        return RestaurantTable::whereKey($table->id)->value('closed_at') !== null;
    }

    // ── the amount ───────────────────────────────────────────────────────────

    public function test_the_fee_is_a_percentage_of_the_products_rounded_half_up_and_capped(): void
    {
        $this->assertSame(1000, ServiceFee::cents('percent', '10.00', null, 10000));
        // 10% of R$ 10,05 is 100.5 cents: rounded up.
        $this->assertSame(101, ServiceFee::cents('percent', '10.00', null, 1005));
        $this->assertSame(1234, ServiceFee::cents('percent', '12.34', null, 10000));
        // The cap holds however large the bill.
        $this->assertSame(5000, ServiceFee::cents('percent', '10.00', '50.00', 100000));
        $this->assertSame(1000, ServiceFee::cents('percent', '10.00', '50.00', 10000));
    }

    public function test_a_fixed_fee_is_the_amount_and_nothing_is_charged_on_an_empty_bill(): void
    {
        $this->assertSame(500, ServiceFee::cents('fixed', '5.00', null, 300));
        $this->assertSame(0, ServiceFee::cents('fixed', '5.00', null, 0));
        $this->assertSame(0, ServiceFee::cents('percent', '10.00', null, 0));
        $this->assertSame(0, ServiceFee::cents(null, null, null, 10000));
    }

    // ── the setting ──────────────────────────────────────────────────────────

    public function test_the_owner_sets_a_percentage_with_a_cap(): void
    {
        $this->percent(10, 50);

        $this->restaurant->refresh();
        $this->assertSame('percent', $this->restaurant->service_fee_type);
        $this->assertSame('10.00', $this->restaurant->service_fee_value);
        $this->assertSame('50.00', $this->restaurant->service_fee_cap);

        $this->actingAs($this->restaurant)->get(route('restaurant.establishment.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('serviceFee', ['type' => 'percent', 'value' => 10, 'cap' => 50])
                ->where('serviceFeeMaxPercent', ServiceFee::MAX_PERCENT));
    }

    public function test_a_fixed_fee_has_no_cap_and_none_clears_everything(): void
    {
        $this->configure(['service_fee_type' => 'fixed', 'service_fee_value' => 5, 'service_fee_cap' => 50])
            ->assertSessionHasNoErrors();

        $this->restaurant->refresh();
        $this->assertSame('fixed', $this->restaurant->service_fee_type);
        $this->assertSame('5.00', $this->restaurant->service_fee_value);
        $this->assertNull($this->restaurant->service_fee_cap);

        $this->configure(['service_fee_type' => null, 'service_fee_value' => 5])->assertSessionHasNoErrors();

        $this->restaurant->refresh();
        $this->assertNull($this->restaurant->service_fee_type);
        $this->assertNull($this->restaurant->service_fee_value);
    }

    public function test_the_setting_is_validated(): void
    {
        $this->configure(['service_fee_type' => 'percent'])->assertSessionHasErrors('service_fee_value');
        $this->configure(['service_fee_type' => 'percent', 'service_fee_value' => ServiceFee::MAX_PERCENT + 1])
            ->assertSessionHasErrors('service_fee_value');
        $this->configure(['service_fee_type' => 'percent', 'service_fee_value' => 0])->assertSessionHasErrors('service_fee_value');
        $this->configure(['service_fee_type' => 'percent', 'service_fee_value' => 10, 'service_fee_cap' => -1])
            ->assertSessionHasErrors('service_fee_cap');
        $this->configure(['service_fee_type' => 'tip', 'service_fee_value' => 10])->assertSessionHasErrors('service_fee_type');

        $this->assertNull($this->restaurant->fresh()?->service_fee_type);
    }

    public function test_only_the_owner_changes_the_setting(): void
    {
        $waiter = $this->waiterOf($this->restaurant);

        // Staff are sent away from the owner's settings.
        $this->configure(['service_fee_type' => 'percent', 'service_fee_value' => 10], $waiter)->assertRedirect(route('dashboard'));

        $this->assertNull($this->restaurant->fresh()?->service_fee_type);
    }

    // ── each table's copy ────────────────────────────────────────────────────

    public function test_a_table_keeps_the_terms_it_opened_with(): void
    {
        $before = $this->openTable($this->restaurant, ['title' => 'Antes']);
        $this->percent(10);
        $this->actingAs($this->restaurant)->post(route('restaurant.tables.store'), ['title' => 'Depois']);
        $after = RestaurantTable::where('title', 'Depois')->firstOrFail();

        // Changing the setting again doesn't touch the bill already running.
        $this->configure(['service_fee_type' => 'fixed', 'service_fee_value' => 3])->assertSessionHasNoErrors();

        $this->assertNull($before->fresh()?->service_fee_type);
        $this->assertSame('percent', $after->fresh()?->service_fee_type);
        $this->assertSame('10.00', $after->fresh()?->service_fee_value);
    }

    // ── closing ──────────────────────────────────────────────────────────────

    public function test_a_table_closes_only_once_the_products_and_the_fee_are_paid(): void
    {
        $this->percent(10);
        $table = $this->openTable($this->restaurant);
        $this->billLine($table, $this->product($this->restaurant), 3, 10.00);

        $this->pay($table, 30);
        $this->close($table);
        $this->assertFalse($this->isClosed($table));

        $this->pay($table, 3);
        $this->close($table)->assertRedirect(route('restaurant.tables.index'));
        $this->assertTrue($this->isClosed($table));
    }

    public function test_the_fee_respects_the_cap_when_closing(): void
    {
        $this->percent(10, 5);
        $table = $this->openTable($this->restaurant);
        $this->billLine($table, $this->product($this->restaurant), 10, 10.00);

        $this->pay($table, 105);
        $this->close($table);

        $this->assertTrue($this->isClosed($table));
    }

    // ── waiving ──────────────────────────────────────────────────────────────

    public function test_staff_take_the_fee_off_a_table_and_put_it_back(): void
    {
        $this->percent(10);
        $table = $this->openTable($this->restaurant);
        $this->billLine($table, $this->product($this->restaurant), 2, 10.00);
        $waiter = $this->waiterOf($this->restaurant);

        $this->actingAs($waiter)->patch(route('restaurant.tables.service-fee', $table), ['waived' => true])
            ->assertRedirect(route('restaurant.tables.show', $table));
        $this->assertTrue((bool) $table->fresh()?->service_fee_waived);

        $this->actingAs($this->restaurant)->get(route('restaurant.tables.show', $table))
            ->assertInertia(fn (Assert $page) => $page->where('serviceFee.waived', true));

        $this->pay($table, 20);
        $this->close($table);
        $this->assertTrue($this->isClosed($table));
    }

    public function test_putting_the_fee_back_charges_it_again(): void
    {
        $this->percent(10);
        $table = $this->openTable($this->restaurant);
        $this->billLine($table, $this->product($this->restaurant), 2, 10.00);

        $this->actingAs($this->restaurant)->patch(route('restaurant.tables.service-fee', $table), ['waived' => true]);
        $this->actingAs($this->restaurant)->patch(route('restaurant.tables.service-fee', $table), ['waived' => false]);

        $this->pay($table, 20);
        $this->close($table);
        $this->assertFalse($this->isClosed($table));
    }

    public function test_waiving_is_refused_for_other_restaurants_closed_tables_and_tables_without_fee(): void
    {
        $this->percent(10);
        $table = $this->openTable($this->restaurant);
        $closed = $this->closedTable($this->restaurant);
        $other = $this->restaurant();
        $noFee = $this->openTable($other);

        $this->actingAs($other)->patch(route('restaurant.tables.service-fee', $table), ['waived' => true])->assertForbidden();
        $this->actingAs($this->restaurant)->patch(route('restaurant.tables.service-fee', $closed), ['waived' => true])->assertStatus(422);
        $this->actingAs($other)->patch(route('restaurant.tables.service-fee', $noFee), ['waived' => true])->assertNotFound();

        $this->assertFalse((bool) $table->fresh()?->service_fee_waived);
    }

    // ── what people see ──────────────────────────────────────────────────────

    public function test_the_customer_sees_the_fee_apart_from_the_products_and_the_total(): void
    {
        $this->percent(10, 50);
        $table = $this->openTable($this->restaurant);
        $this->billLine($table, $this->product($this->restaurant), 3, 10.00);
        $this->pay($table, 10);

        $this->actingAs($this->customer())->get(route('customer.table', (string) $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/show')
                ->where('subtotal', 30)
                ->where('serviceFee', 3)
                ->where('serviceFeeTerms', ['type' => 'percent', 'value' => 10, 'cap' => 50, 'waived' => false])
                ->where('total', 33)
                ->where('remaining', 23));
    }

    public function test_a_waived_fee_disappears_from_the_customers_bill(): void
    {
        $this->percent(10);
        $table = $this->openTable($this->restaurant);
        $this->billLine($table, $this->product($this->restaurant), 3, 10.00);
        $table->forceFill(['service_fee_waived' => true])->save();

        $this->actingAs($this->customer())->get(route('customer.table', (string) $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('serviceFee', 0)
                ->where('serviceFeeTerms', null)
                ->where('total', 30));
    }

    public function test_the_tables_board_shows_the_fee_of_each_table(): void
    {
        $this->configure(['service_fee_type' => 'fixed', 'service_fee_value' => 5])->assertSessionHasNoErrors();
        $table = $this->openTable($this->restaurant);
        $this->billLine($table, $this->product($this->restaurant), 1, 10.00);
        $this->openTable($this->restaurant, ['title' => 'Mesa 2']);

        $this->actingAs($this->restaurant)->get(route('restaurant.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.0.order_total', 10)
                ->where('tables.0.service_fee', 5)
                // Nothing delivered: no fee yet.
                ->where('tables.1.service_fee', 0));
    }

    public function test_customers_can_pay_the_fee_in_the_app(): void
    {
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
        $this->restaurant->forceFill(['stripe_account_id' => 'acct_restaurant', 'stripe_charges_enabled' => true])->save();
        $this->percent(10);
        $table = $this->openTable($this->restaurant);
        $this->billLine($table, $this->product($this->restaurant), 5, 10.00);
        $customer = $this->customer();
        $this->actingAs($customer)->get(route('customer.table', (string) $table->access_code))->assertOk();

        $this->actingAs($customer)->post(route('customer.payments.store', (string) $table->access_code), ['amount' => 55.01])
            ->assertSessionHasErrors('amount');
        $this->actingAs($customer)->post(route('customer.payments.store', (string) $table->access_code), ['amount' => 55])
            ->assertSessionHasNoErrors();
    }
}
