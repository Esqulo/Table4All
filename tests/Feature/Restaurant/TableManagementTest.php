<?php

namespace Tests\Feature\Restaurant;

use App\Enums\QueueItemStatus;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

class TableManagementTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ── creating and listing ─────────────────────────────────────────────────

    public function test_table_created_by_a_waiter_belongs_to_the_restaurant(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($this->waiterOf($restaurant))
            ->post(route('restaurant.tables.store'), ['title' => 'Varanda 3'])
            ->assertRedirect(route('restaurant.tables.index'));

        $table = RestaurantTable::sole();
        $this->assertSame($restaurant->id, $table->user_id);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $table->access_code);
    }

    public function test_table_title_is_required_and_bounded(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.tables.store'), ['title' => ''])->assertSessionHasErrors('title');
        $this->actingAs($restaurant)->post(route('restaurant.tables.store'), ['title' => str_repeat('a', 256)])->assertSessionHasErrors('title');
        $this->assertSame(0, RestaurantTable::count());
    }

    public function test_access_code_never_repeats_an_open_table_of_any_restaurant(): void
    {
        // Customers join by code alone, so a code taken by another restaurant must be skipped.
        $this->openTable($this->restaurant(), ['access_code' => '000042']);
        $numbers = [42, 43];

        $code = RestaurantTable::generateUniqueCode(function () use (&$numbers) {
            return array_shift($numbers);
        });

        $this->assertSame('000043', $code);
    }

    public function test_access_code_of_a_closed_table_can_be_reused(): void
    {
        $this->closedTable($this->restaurant());

        $this->assertSame('000042', RestaurantTable::generateUniqueCode(fn () => 42));
    }

    public function test_board_lists_only_the_restaurants_open_tables(): void
    {
        $restaurant = $this->restaurant();
        $this->openTable($restaurant, ['title' => 'Aberta']);
        $this->closedTable($restaurant, ['title' => 'Fechada']);
        $this->openTable($restaurant, ['title' => 'Excluída'])->delete();
        $this->openTable($this->restaurant(), ['title' => 'De outro']);

        $this->actingAs($restaurant)->get(route('restaurant.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tables', 1)
                ->where('tables.0.title', 'Aberta')
            );
    }

    public function test_board_shows_zero_totals_for_an_empty_table(): void
    {
        $restaurant = $this->restaurant();
        $this->openTable($restaurant);

        $this->actingAs($restaurant)->get(route('restaurant.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.0.order_total', 0)
                ->where('tables.0.paid_total', 0)
                ->where('tables.0.products_count', 0)
            );
    }

    public function test_renaming_a_table_keeps_its_order(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $this->billLine($table, $this->product($restaurant), 2, 10);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.update', $table), ['title' => 'Mesa VIP'])
            ->assertRedirect(route('restaurant.tables.index'));

        $this->assertSame('Mesa VIP', $table->fresh()->title);
        $this->assertSame(1, DB::table('restaurant_table_product')->count());
    }

    public function test_opening_a_closed_table_sends_back_to_the_board(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->closedTable($restaurant);

        $this->actingAs($restaurant)->get(route('restaurant.tables.show', $table))
            ->assertRedirect(route('restaurant.tables.index'));
    }

    // ── saving the order ─────────────────────────────────────────────────────

    public function test_order_save_rejects_absurd_quantities_and_prices(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $product = $this->product($restaurant);

        foreach ([
            ['product_id' => $product->id, 'price' => 10, 'quantity' => 10000],
            ['product_id' => $product->id, 'price' => 100000, 'quantity' => 1],
            ['product_id' => $product->id, 'price' => -1, 'quantity' => 1],
            ['product_id' => $product->id, 'price' => 10, 'quantity' => 0],
        ] as $line) {
            $this->actingAs($restaurant)
                ->patch(route('restaurant.tables.update', $table), ['products' => [$line]])
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, DB::table('restaurant_table_product')->count());
    }

    public function test_order_save_ignores_products_of_other_restaurants(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $foreign = $this->product($this->restaurant());

        $this->actingAs($restaurant)->patch(route('restaurant.tables.update', $table), [
            'products' => [['product_id' => $foreign->id, 'price' => 1, 'quantity' => 1]],
            'queue_additions' => [['product_id' => $foreign->id, 'price' => 1, 'quantity' => 1]],
        ]);

        $this->assertSame(0, DB::table('restaurant_table_product')->count());
        $this->assertSame(0, DB::table('queue_items')->count());
    }

    public function test_queue_addition_for_a_product_without_queue_is_ignored(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.update', $table), [
            'queue_additions' => [['product_id' => $this->product($restaurant)->id, 'price' => 10, 'quantity' => 1]],
        ]);

        $this->assertSame(0, DB::table('queue_items')->count());
    }

    public function test_order_save_without_version_from_older_clients_still_works(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $product = $this->product($restaurant);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.update', $table), [
            'products' => [['product_id' => $product->id, 'price' => 10, 'quantity' => 2]],
        ]);

        $this->assertSame(2, (int) DB::table('restaurant_table_product')->sole()->quantity);
    }

    public function test_same_product_at_two_prices_is_kept_as_two_lines(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $product = $this->product($restaurant);
        // One unit at full price, one served during a promotion (the bill already has both).
        $this->billLine($table, $product, 1, 10);
        $this->billLine($table, $product, 1, 7.5);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.update', $table), [
            'products' => [
                ['product_id' => $product->id, 'price' => 10, 'quantity' => 1],
                ['product_id' => $product->id, 'price' => 7.5, 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(25.0, DB::table('restaurant_table_product')->sum(DB::raw('price * quantity')));
        $this->assertSame(2, DB::table('restaurant_table_product')->count());
    }

    // ── payments ─────────────────────────────────────────────────────────────

    public function test_payment_is_validated(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);

        foreach ([
            ['method' => 'cash', 'amount' => 0],
            ['method' => 'cash', 'amount' => -5],
            ['method' => 'cash', 'amount' => 100000],
            ['method' => 'bitcoin', 'amount' => 10],
            ['amount' => 10],
        ] as $payload) {
            $this->actingAs($restaurant)->post(route('restaurant.tables.add-payment', $table), $payload)
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, $table->payments()->count());
    }

    public function test_payment_records_who_registered_it(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);
        $table = $this->openTable($restaurant);

        $this->actingAs($waiter)->post(route('restaurant.tables.add-payment', $table), ['method' => 'pix', 'amount' => '12.34']);

        $payment = $table->payments()->sole();
        $this->assertSame($waiter->id, $payment->registered_by_id);
        $this->assertSame('waiter', $payment->registered_by_type);
        $this->assertEquals(12.34, (float) $payment->amount);
    }

    public function test_payment_on_a_closed_table_is_refused(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->closedTable($restaurant);

        $this->actingAs($restaurant)->post(route('restaurant.tables.add-payment', $table), ['method' => 'cash', 'amount' => 5])
            ->assertStatus(422);
        $this->assertSame(0, $table->payments()->count());
    }

    // ── closing ──────────────────────────────────────────────────────────────

    public function test_table_cannot_close_while_payments_fall_short(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $this->billLine($table, $this->product($restaurant), 3, 10);
        $table->payments()->create(['method' => 'cash', 'amount' => 29.99]);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table))
            ->assertRedirect(route('restaurant.tables.show', $table));

        $this->assertNull($table->fresh()->closed_at);
    }

    public function test_exact_payment_closes_the_table_and_frees_its_code(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $code = $table->access_code;
        $this->billLine($table, $this->product($restaurant), 3, 10);
        $table->payments()->create(['method' => 'cash', 'amount' => 20]);
        $table->payments()->create(['method' => 'card', 'amount' => 10]);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table))
            ->assertRedirect(route('restaurant.tables.index'));

        $table->refresh();
        $this->assertNotNull($table->closed_at);
        $this->assertNull($table->access_code);
        $this->actingAs($this->customer())->get(route('customer.table', $code))->assertRedirect(route('customer.join'));
    }

    public function test_overpaid_table_closes(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $this->billLine($table, $this->product($restaurant), 1, 10);
        $table->payments()->create(['method' => 'cash', 'amount' => 50]);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table));

        $this->assertNotNull($table->fresh()->closed_at);
    }

    public function test_totals_are_compared_at_cent_precision(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        // 3 x 0.10 is 0.30000000000000004 in floating point.
        $this->billLine($table, $this->product($restaurant), 3, 0.1);
        $table->payments()->create(['method' => 'cash', 'amount' => 0.3]);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table));

        $this->assertNotNull($table->fresh()->closed_at);
    }

    public function test_closing_an_already_closed_table_is_refused(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->closedTable($restaurant);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table))->assertStatus(422);
    }

    public function test_deleted_table_disappears_and_its_code_stops_working(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);

        $this->actingAs($restaurant)->delete(route('restaurant.tables.destroy', $table))
            ->assertRedirect(route('restaurant.tables.index'));

        $this->assertSoftDeleted($table);
        $this->get(route('table.show', $table->access_code))->assertRedirect(route('table.join'));
    }

    // ── undelivered items at closing ─────────────────────────────────────────

    public function test_closing_records_undelivered_items_as_abandoned(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['queue_id' => $kitchen->id]);
        $table = $this->openTable($restaurant);
        $cooking = $this->queueItem($table, $burger);
        $ready = $this->queueItem($table, $burger, ['status' => 'done']);
        $served = $this->queueItem($table, $burger, ['status' => 'delivered']);
        $otherTable = $this->queueItem($this->openTable($restaurant), $burger);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table))
            ->assertRedirect(route('restaurant.tables.index'));

        $this->assertSame(QueueItemStatus::ABANDONED, $cooking->fresh()->status);
        $this->assertSame(QueueItemStatus::ABANDONED, $ready->fresh()->status);
        $this->assertSame(QueueItemStatus::DELIVERED, $served->fresh()->status);
        $this->assertSame(QueueItemStatus::PENDING, $otherTable->fresh()->status);
    }

    public function test_refused_closing_leaves_items_owed(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        $table = $this->openTable($restaurant);
        $this->billLine($table, $product, 1, 10);
        $item = $this->queueItem($table, $product);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table)); // nothing paid

        $this->assertSame(QueueItemStatus::PENDING, $item->fresh()->status);
    }

    public function test_abandoned_items_are_not_charged(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $this->queueItem($table, $this->product($restaurant), ['price' => 30]);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table));

        $this->assertNotNull($table->fresh()->closed_at);
        $this->assertSame(0, DB::table('restaurant_table_product')->count());
    }
}
