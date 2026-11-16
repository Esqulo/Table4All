<?php

namespace Tests\Feature;

use App\Enums\QueueItemStatus;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantQueue;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Guarantees the app relies on during a rush: retried or concurrent writes
 * (slow connections, double taps, two staff members on the same table)
 * never duplicate or lose order lines and payments.
 *
 * @see docs/performance.md
 */
class RushResilienceTest extends TestCase
{
    use RefreshDatabase;

    private User $restaurant;

    private RestaurantTable $table;

    private Product $beer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->restaurant = User::factory()->create(['account_type' => 'restaurant']);
        $this->table = RestaurantTable::create([
            'user_id' => $this->restaurant->id,
            'title' => 'Mesa 1',
            'access_code' => '123456',
        ]);
        $this->beer = Product::create([
            'user_id' => $this->restaurant->id,
            'name' => 'Chopp',
            'price' => 10,
            'price_type' => 'unit',
        ]);
    }

    public function test_customer_order_resent_with_the_same_key_is_recorded_once(): void
    {
        $customer = User::factory()->create();
        $payload = [
            'items' => [['product_id' => $this->beer->id, 'quantity' => 2]],
            'request_key' => 'cart-abc',
        ];

        $this->actingAs($customer)->post(route('customer.order', '123456'), $payload)->assertRedirect();
        $this->actingAs($customer)->post(route('customer.order', '123456'), $payload)->assertRedirect();

        $this->assertSame(1, QueueItem::count());
        $this->assertSame(2, (int) QueueItem::sole()->quantity);
    }

    public function test_customer_orders_with_different_keys_are_both_recorded(): void
    {
        $customer = User::factory()->create();

        foreach (['cart-1', 'cart-2'] as $key) {
            $this->actingAs($customer)->post(route('customer.order', '123456'), [
                'items' => [['product_id' => $this->beer->id, 'quantity' => 1]],
                'request_key' => $key,
            ]);
        }

        $this->assertSame(2, QueueItem::count());
    }

    public function test_customer_order_snapshots_the_active_sale_price(): void
    {
        Sale::create([
            'user_id' => $this->restaurant->id,
            'product_id' => $this->beer->id,
            'type' => 'periodic',
            'sale_price' => 7.5,
            'days' => [0, 1, 2, 3, 4, 5, 6],
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
        ]);

        $this->actingAs(User::factory()->create())->post(route('customer.order', '123456'), [
            'items' => [['product_id' => $this->beer->id, 'quantity' => 1]],
        ]);

        $this->assertEquals(7.5, (float) QueueItem::sole()->price);
    }

    public function test_marking_an_item_delivered_twice_bills_it_once(): void
    {
        $item = QueueItem::create([
            'restaurant_table_id' => $this->table->id,
            'product_id' => $this->beer->id,
            'quantity' => 3,
            'price' => 10,
            'status' => QueueItemStatus::PENDING->value,
        ]);

        $this->actingAs($this->restaurant)->patch(route('restaurant.queue-items.mark-delivered', $item));
        $this->actingAs($this->restaurant)->patch(route('restaurant.queue-items.mark-delivered', $item));

        $lines = DB::table('restaurant_table_product')->where('restaurant_table_id', $this->table->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame(3, (int) $lines[0]->quantity);
        $this->assertSame(QueueItemStatus::DELIVERED, $item->fresh()->status);
    }

    public function test_delivering_more_of_the_same_product_adds_to_the_existing_line(): void
    {
        foreach ([1, 2] as $qty) {
            $item = QueueItem::create([
                'restaurant_table_id' => $this->table->id,
                'product_id' => $this->beer->id,
                'quantity' => $qty,
                'price' => 10,
                'status' => QueueItemStatus::PENDING->value,
            ]);
            $this->actingAs($this->restaurant)->patch(route('restaurant.queue-items.mark-delivered', $item));
        }

        $this->assertSame(3, (int) DB::table('restaurant_table_product')->sole()->quantity);
    }

    public function test_marking_done_only_moves_pending_items(): void
    {
        $item = QueueItem::create([
            'restaurant_table_id' => $this->table->id,
            'product_id' => $this->beer->id,
            'quantity' => 1,
            'price' => 10,
            'status' => QueueItemStatus::DELIVERED->value,
        ]);

        $this->actingAs($this->restaurant)->patch(route('restaurant.queue-items.mark-done', $item));

        $this->assertSame(QueueItemStatus::DELIVERED, $item->fresh()->status);
    }

    public function test_staff_from_another_restaurant_cannot_change_queue_items(): void
    {
        $item = QueueItem::create([
            'restaurant_table_id' => $this->table->id,
            'product_id' => $this->beer->id,
            'quantity' => 1,
            'price' => 10,
            'status' => QueueItemStatus::PENDING->value,
        ]);
        $other = User::factory()->create(['account_type' => 'restaurant']);

        $this->actingAs($other)->patch(route('restaurant.queue-items.mark-done', $item))->assertForbidden();
    }

    public function test_saving_an_order_from_a_stale_screen_is_rejected_and_keeps_new_deliveries(): void
    {
        $staleVersion = $this->orderVersionShownToWaiter();

        // A delivery lands while the waiter is still looking at the old screen.
        $item = QueueItem::create([
            'restaurant_table_id' => $this->table->id,
            'product_id' => $this->beer->id,
            'quantity' => 2,
            'price' => 10,
            'status' => QueueItemStatus::DONE->value,
        ]);
        $this->actingAs($this->restaurant)->patch(route('restaurant.queue-items.mark-delivered', $item));

        $this->actingAs($this->restaurant)
            ->patch(route('restaurant.tables.update', $this->table), [
                'products' => [],
                'order_version' => $staleVersion,
            ])
            ->assertRedirect(route('restaurant.tables.show', $this->table));

        $this->assertSame(2, (int) DB::table('restaurant_table_product')->sole()->quantity);
    }

    public function test_saving_an_order_with_the_current_version_applies_it(): void
    {
        $queue = RestaurantQueue::create(['user_id' => $this->restaurant->id, 'name' => 'Cozinha']);
        $burger = Product::create([
            'user_id' => $this->restaurant->id,
            'queue_id' => $queue->id,
            'name' => 'Burger',
            'price' => 30,
            'price_type' => 'unit',
        ]);

        $this->actingAs($this->restaurant)->patch(route('restaurant.tables.update', $this->table), [
            'products' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 4]],
            'queue_additions' => [['product_id' => $burger->id, 'price' => 30, 'quantity' => 2]],
            'order_version' => $this->orderVersionShownToWaiter(),
        ]);

        $this->assertSame(4, (int) DB::table('restaurant_table_product')->sole()->quantity);
        $this->assertSame(2, (int) QueueItem::where('product_id', $burger->id)->sole()->quantity);
    }

    public function test_payment_resent_with_the_same_key_is_recorded_once(): void
    {
        $payload = ['method' => 'pix', 'amount' => '25.00', 'request_key' => 'pay-1'];

        $this->actingAs($this->restaurant)->post(route('restaurant.tables.add-payment', $this->table), $payload);
        $this->actingAs($this->restaurant)->post(route('restaurant.tables.add-payment', $this->table), $payload);

        $this->assertSame(1, $this->table->payments()->count());
    }

    public function test_tables_board_totals_use_order_time_prices(): void
    {
        DB::table('restaurant_table_product')->insert([
            'restaurant_table_id' => $this->table->id,
            'product_id' => $this->beer->id,
            'quantity' => 2,
            'price' => 8,
        ]);
        $this->table->payments()->create(['method' => 'cash', 'amount' => 5]);

        // Price changed after the order was placed: the table still owes the old price.
        $this->beer->update(['price' => 12]);

        $this->actingAs($this->restaurant)
            ->get(route('restaurant.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('restaurant/tables')
                ->where('tables.0.order_total', 16)
                ->where('tables.0.paid_total', 5)
                ->where('tables.0.products_count', 1)
            );
    }

    private function orderVersionShownToWaiter(): string
    {
        $version = null;

        $this->actingAs($this->restaurant)
            ->get(route('restaurant.tables.show', $this->table))
            ->assertInertia(function (Assert $page) use (&$version) {
                $page->component('restaurant/tables/show')->has('orderVersion');
                $version = $page->toArray()['props']['orderVersion'];
            });

        $this->assertIsString($version);

        return $version;
    }
}
