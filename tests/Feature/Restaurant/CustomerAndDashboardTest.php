<?php

namespace Tests\Feature\Restaurant;

use App\Models\QueueItem;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Customer self-ordering by table code, and the owner's sales dashboard. */
class CustomerAndDashboardTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── joining ──────────────────────────────────────────────────────────────

    public function test_public_table_page_shows_that_restaurants_menu(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $this->product($restaurant, ['name' => 'Chopp']);
        $this->product($this->restaurant(), ['name' => 'De outro bar']);
        $table = $this->openTable($restaurant);

        $this->get(route('table.show', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('table/show')
                ->where('restaurant.name', 'Bar do Zé')
                ->has('products', 1)
                ->where('products.0.name', 'Chopp')
            );
    }

    public function test_unknown_closed_or_deleted_table_codes_are_rejected(): void
    {
        $restaurant = $this->restaurant();
        $deleted = $this->openTable($restaurant);
        $deleted->delete();

        foreach (['000000', 'abc', $deleted->access_code] as $code) {
            $this->get(route('table.show', $code))->assertRedirect(route('table.join'));
            $this->actingAs($this->customer())->get(route('customer.table', $code))->assertRedirect(route('customer.join'));
        }
    }

    public function test_customer_routes_require_login(): void
    {
        $table = $this->openTable($this->restaurant());

        $this->get(route('customer.table', $table->access_code))->assertRedirect(route('login'));
        $this->post(route('customer.order', $table->access_code), [])->assertRedirect(route('login'));
    }

    public function test_customer_sees_balance_never_below_zero(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $this->billLine($table, $this->product($restaurant), 2, 10);
        $table->payments()->create(['method' => 'cash', 'amount' => 50]);

        $this->actingAs($this->customer())->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 20)
                ->where('paid', 50)
                ->where('remaining', 0)
            );
    }

    public function test_customer_menu_shows_promotion_prices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 20:30:00', 'UTC')); // Friday 17:30 in Brasilia
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant, ['price' => 10]);
        Sale::create([
            'user_id' => $restaurant->id, 'product_id' => $product->id, 'type' => 'periodic',
            'sale_price' => 6, 'days' => [5], 'start_time' => '17:00', 'end_time' => '20:00',
        ]);
        $table = $this->openTable($restaurant);

        $this->actingAs($this->customer())->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page->where('menu.0.price', 6));
    }

    public function test_customer_sees_progress_of_an_item_whose_product_was_deleted(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant, ['name' => 'Burger']);
        $table = $this->openTable($restaurant);
        $this->queueItem($table, $product);
        $product->delete();

        $this->actingAs($this->customer())->get(route('customer.table', $table->access_code))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('preparing.0.name', 'Burger'));
    }

    // ── ordering ─────────────────────────────────────────────────────────────

    public function test_kitchen_items_go_to_their_queue_and_counter_items_to_none(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['queue_id' => $kitchen->id]);
        $beer = $this->product($restaurant);
        $table = $this->openTable($restaurant);
        $customer = $this->customer();

        $this->actingAs($customer)->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $burger->id, 'quantity' => 1], ['product_id' => $beer->id, 'quantity' => 3]],
        ])->assertRedirect(route('customer.table', $table->access_code));

        $this->assertSame($kitchen->id, QueueItem::where('product_id', $burger->id)->sole()->queue_id);
        $this->assertNull(QueueItem::where('product_id', $beer->id)->sole()->queue_id);
        $this->assertSame(2, QueueItem::where('ordered_by_user_id', $customer->id)->where('status', 'pending')->count());
    }

    public function test_order_skips_products_of_other_restaurants(): void
    {
        $restaurant = $this->restaurant();
        $own = $this->product($restaurant);
        $foreign = $this->product($this->restaurant());
        $table = $this->openTable($restaurant);

        $this->actingAs($this->customer())->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $own->id, 'quantity' => 1], ['product_id' => $foreign->id, 'quantity' => 1]],
        ]);

        $this->assertSame([$own->id], QueueItem::pluck('product_id')->all());
    }

    public function test_order_with_nothing_orderable_is_reported_not_confirmed(): void
    {
        $restaurant = $this->restaurant();
        $gone = $this->product($restaurant);
        $gone->delete();
        $table = $this->openTable($restaurant);

        $this->actingAs($this->customer())->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $gone->id, 'quantity' => 1]],
        ])->assertRedirect(route('customer.table', $table->access_code))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertSame(0, QueueItem::count());
    }

    public function test_order_quantities_are_bounded(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        $table = $this->openTable($restaurant);
        $customer = $this->customer();

        foreach ([
            [],
            ['items' => []],
            ['items' => [['product_id' => $product->id, 'quantity' => 0]]],
            ['items' => [['product_id' => $product->id, 'quantity' => 100]]],
            ['items' => [['product_id' => $product->id]]],
            ['items' => array_fill(0, 101, ['product_id' => $product->id, 'quantity' => 1])],
        ] as $payload) {
            $this->actingAs($customer)->post(route('customer.order', $table->access_code), $payload)->assertSessionHasErrors();
        }

        $this->assertSame(0, QueueItem::count());
    }

    public function test_order_on_a_closed_table_is_not_found(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $code = $table->access_code;
        $table->forceFill(['closed_at' => now()])->save(); // code not yet cleared

        $this->actingAs($this->customer())->post(route('customer.order', $code), [
            'items' => [['product_id' => $this->product($restaurant)->id, 'quantity' => 1]],
        ])->assertNotFound();
    }

    // ── dashboard ────────────────────────────────────────────────────────────

    public function test_dashboard_counts_only_this_restaurants_closed_tables_in_the_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 15:00:00', 'UTC'));
        $restaurant = $this->restaurant();
        $beer = $this->product($restaurant, ['name' => 'Chopp', 'price' => 99]); // current price must not matter

        $counted = $this->closedTable($restaurant);
        $this->billLine($counted, $beer, 3, 10);
        $counted->payments()->create(['method' => 'pix', 'amount' => 30]);

        $this->billLine($this->openTable($restaurant), $beer, 5, 10);                       // still open
        $this->billLine($deleted = $this->closedTable($restaurant), $beer, 7, 10);
        $deleted->delete();                                                                  // deleted
        $old = $this->closedTable($restaurant);
        $old->forceFill(['closed_at' => now()->subDays(40)])->save();
        $this->billLine($old, $beer, 9, 10);                                                 // outside 30 days
        $other = $this->restaurant();
        $this->billLine($this->closedTable($other), $this->product($other), 11, 10);        // someone else

        $this->actingAs($restaurant)->get(route('restaurant.dashboards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period', 30)
                ->where('summary.total_revenue', 30)
                ->where('summary.tables_closed', 1)
                ->where('summary.items_sold', 3)
                ->where('summary.avg_table_revenue', 30)
                ->where('top_products.0.name', 'Chopp')
                ->has('recent_tables', 1)
                ->where('payment_methods.0.method', 'pix')
            );
    }

    public function test_dashboard_with_no_sales_shows_zeros(): void
    {
        $this->actingAs($this->restaurant())->get(route('restaurant.dashboards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_revenue', 0)
                ->where('summary.tables_closed', 0)
                ->where('summary.avg_table_revenue', 0)
                ->has('revenue_by_day', 0)
            );
    }

    public function test_dashboard_period_falls_back_to_thirty_days(): void
    {
        $restaurant = $this->restaurant();

        foreach (['365', 'abc', '-7'] as $period) {
            $this->actingAs($restaurant)->get(route('restaurant.dashboards.index', ['period' => $period]))
                ->assertInertia(fn (Assert $page) => $page->where('period', 30));
        }

        $this->actingAs($restaurant)->get(route('restaurant.dashboards.index', ['period' => 7]))
            ->assertInertia(fn (Assert $page) => $page->where('period', 7));
    }

    public function test_late_night_sales_count_on_the_local_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00', 'UTC'));
        $restaurant = $this->restaurant();
        $table = $this->closedTable($restaurant);
        // 01:30 UTC on the 21st is 22:30 on the 20th in Brasilia.
        $table->forceFill(['closed_at' => Carbon::parse('2026-11-21 01:30:00', 'UTC')])->save();
        $this->billLine($table, $this->product($restaurant), 1, 10);

        $this->actingAs($restaurant)->get(route('restaurant.dashboards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('revenue_by_day', 1)
                ->where('revenue_by_day.0.date', '2026-11-20')
            );
    }

    public function test_dashboard_reports_orders_customers_left_without(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 15:00:00', 'UTC'));
        $restaurant = $this->restaurant();
        $burger = $this->product($restaurant, ['price' => 30]);

        $left = $this->closedTable($restaurant);
        $this->queueItem($left, $burger, ['status' => 'abandoned', 'quantity' => 2, 'price' => 25]);
        $this->queueItem($left, $burger, ['status' => 'delivered', 'quantity' => 5]);
        $old = $this->closedTable($restaurant);
        $old->forceFill(['closed_at' => now()->subDays(40)])->save();
        $this->queueItem($old, $burger, ['status' => 'abandoned']);
        $other = $this->restaurant();
        $this->queueItem($this->closedTable($other), $this->product($other), ['status' => 'abandoned']);

        $this->actingAs($restaurant)->get(route('restaurant.dashboards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.abandoned_items', 2)
                ->where('summary.abandoned_value', 50)
                ->where('summary.total_revenue', 0)
            );
    }
}
