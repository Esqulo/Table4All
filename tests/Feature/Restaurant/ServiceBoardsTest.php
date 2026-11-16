<?php

namespace Tests\Feature\Restaurant;

use App\Enums\QueueItemStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * Kitchen queue (restaurant.queues.show) and delivery board
 * (restaurant.delivery.index): what shows up, and when status changes apply.
 */
class ServiceBoardsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ── kitchen queue ────────────────────────────────────────────────────────

    public function test_kitchen_shows_only_its_open_work_in_arrival_order(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant, 'Cozinha');
        $bar = $this->kitchenQueue($restaurant, 'Bar');
        $burger = $this->product($restaurant, ['name' => 'Burger', 'queue_id' => $kitchen->id]);
        $drink = $this->product($restaurant, ['name' => 'Caipirinha', 'queue_id' => $bar->id]);
        $table = $this->openTable($restaurant);

        $second = $this->queueItem($table, $burger, ['created_at' => now()->subMinute()]);
        $first = $this->queueItem($table, $burger, ['status' => 'done', 'created_at' => now()->subMinutes(5)]);
        $this->queueItem($table, $burger, ['status' => 'delivered']);
        $this->queueItem($table, $drink);

        $this->actingAs($restaurant)->get(route('restaurant.queues.show', $kitchen))
            ->assertInertia(fn (Assert $page) => $page
                ->component('restaurant/queues/show')
                ->has('items', 2)
                ->where('items.0.id', $first->id)
                ->where('items.1.id', $second->id)
            );
    }

    public function test_kitchen_drops_items_of_closed_and_deleted_tables(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['queue_id' => $kitchen->id]);
        $this->queueItem($this->closedTable($restaurant), $burger);
        $deleted = $this->openTable($restaurant);
        $this->queueItem($deleted, $burger);
        $deleted->delete();
        $kept = $this->queueItem($this->openTable($restaurant), $burger);

        $this->actingAs($restaurant)->get(route('restaurant.queues.show', $kitchen))
            ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.id', $kept->id));
    }

    public function test_kitchen_keeps_showing_an_order_whose_product_was_deleted(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['name' => 'Burger', 'queue_id' => $kitchen->id]);
        $this->queueItem($this->openTable($restaurant), $burger);

        $burger->delete();

        $this->actingAs($restaurant)->get(route('restaurant.queues.show', $kitchen))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.0.product.name', 'Burger'));
    }

    public function test_queues_list_is_per_restaurant_and_names_are_required(): void
    {
        $restaurant = $this->restaurant();
        $this->kitchenQueue($restaurant, 'Cozinha');
        $this->kitchenQueue($this->restaurant(), 'Alheia');

        $this->actingAs($restaurant)->get(route('restaurant.queues.index'))
            ->assertInertia(fn (Assert $page) => $page->has('queues', 1)->where('queues.0.name', 'Cozinha'));
        $this->actingAs($restaurant)->post(route('restaurant.queues.store'), ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_deleting_a_queue_detaches_its_products(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['queue_id' => $kitchen->id]);

        $this->actingAs($restaurant)->delete(route('restaurant.queues.destroy', $kitchen));

        $this->assertModelMissing($kitchen);
        $this->assertNull($burger->fresh()->queue_id);
    }

    // ── status changes ───────────────────────────────────────────────────────

    public function test_item_of_a_closed_table_cannot_be_marked_done(): void
    {
        $restaurant = $this->restaurant();
        $item = $this->queueItem($this->closedTable($restaurant), $this->product($restaurant));

        $this->actingAs($restaurant)->patch(route('restaurant.queue-items.mark-done', $item))->assertRedirect();

        $this->assertSame(QueueItemStatus::PENDING, $item->fresh()->status);
    }

    public function test_delivering_onto_a_closed_table_adds_no_charge(): void
    {
        $restaurant = $this->restaurant();
        $item = $this->queueItem($this->closedTable($restaurant), $this->product($restaurant), ['status' => 'done']);

        $this->actingAs($restaurant)->patch(route('restaurant.queue-items.mark-delivered', $item));

        $this->assertSame(QueueItemStatus::DONE, $item->fresh()->status);
        $this->assertSame(0, DB::table('restaurant_table_product')->count());
    }

    public function test_delivering_onto_a_deleted_table_adds_no_charge(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $item = $this->queueItem($table, $this->product($restaurant));
        $table->delete();

        $this->actingAs($restaurant)->patch(route('restaurant.queue-items.mark-delivered', $item));

        $this->assertSame(0, DB::table('restaurant_table_product')->count());
    }

    public function test_delivered_item_keeps_the_price_it_was_ordered_at(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant, ['price' => 10]);
        $item = $this->queueItem($this->openTable($restaurant), $product, ['price' => 7.5, 'quantity' => 2]);
        $product->update(['price' => 15]);

        $this->actingAs($this->waiterOf($restaurant))->patch(route('restaurant.queue-items.mark-delivered', $item));

        $line = DB::table('restaurant_table_product')->sole();
        $this->assertEquals(7.5, (float) $line->price);
        $this->assertSame(2, (int) $line->quantity);
    }

    public function test_pending_item_can_be_delivered_directly(): void
    {
        // Products without a prep queue skip "done" and go straight to the table.
        $restaurant = $this->restaurant();
        $item = $this->queueItem($this->openTable($restaurant), $this->product($restaurant));

        $this->actingAs($restaurant)->patch(route('restaurant.queue-items.mark-delivered', $item));

        $this->assertSame(QueueItemStatus::DELIVERED, $item->fresh()->status);
    }

    // ── delivery board ───────────────────────────────────────────────────────

    public function test_delivery_board_shows_ready_kitchen_items_and_counter_items(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['name' => 'Burger', 'queue_id' => $kitchen->id]);
        $beer = $this->product($restaurant, ['name' => 'Chopp']);
        $table = $this->openTable($restaurant);

        $this->queueItem($table, $burger);                          // still cooking
        $ready = $this->queueItem($table, $burger, ['status' => 'done']);
        $counter = $this->queueItem($table, $beer);                 // no queue: waiter serves it
        $this->queueItem($table, $beer, ['status' => 'delivered']);

        $this->actingAs($restaurant)->get(route('restaurant.delivery.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 2)
                ->where('items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all() === collect([$ready->id, $counter->id])->sort()->values()->all())
            );
    }

    public function test_delivery_board_ignores_closed_deleted_and_foreign_tables(): void
    {
        $restaurant = $this->restaurant();
        $beer = $this->product($restaurant);
        $this->queueItem($this->closedTable($restaurant), $beer);
        $deleted = $this->openTable($restaurant);
        $this->queueItem($deleted, $beer);
        $deleted->delete();
        $other = $this->restaurant();
        $this->queueItem($this->openTable($other), $this->product($other));

        $this->actingAs($restaurant)->get(route('restaurant.delivery.index'))
            ->assertInertia(fn (Assert $page) => $page->has('items', 0));
    }

    public function test_delivery_board_survives_a_deleted_product(): void
    {
        $restaurant = $this->restaurant();
        $beer = $this->product($restaurant, ['name' => 'Chopp']);
        $customer = $this->customer();
        $this->queueItem($this->openTable($restaurant), $beer, ['ordered_by_user_id' => $customer->id]);

        Product::whereKey($beer->id)->first()->delete();

        $this->actingAs($this->waiterOf($restaurant))->get(route('restaurant.delivery.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.0.product', 'Chopp')
                ->where('items.0.ordered_by', $customer->name)
            );
    }

    // ── deleting a queue ─────────────────────────────────────────────────────

    public function test_queue_with_work_in_progress_cannot_be_deleted(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['queue_id' => $kitchen->id]);

        foreach (['pending', 'done'] as $status) {
            $item = $this->queueItem($this->openTable($restaurant), $burger, ['status' => $status]);

            $this->actingAs($restaurant)->delete(route('restaurant.queues.destroy', $kitchen))
                ->assertRedirect(route('restaurant.queues.index'))
                ->assertInertiaFlash('toast.type', 'error');

            $this->assertModelExists($kitchen);
            $this->assertSame($kitchen->id, $item->fresh()->queue_id);
            $item->update(['status' => 'delivered']);
        }
    }

    public function test_queue_with_only_finished_history_can_be_deleted(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['queue_id' => $kitchen->id]);
        $table = $this->openTable($restaurant);
        $this->queueItem($table, $burger, ['status' => 'delivered']);
        $this->queueItem($table, $burger, ['status' => 'abandoned']);
        // Leftover pending row on a table closed before items were marked abandoned.
        $this->queueItem($this->closedTable($restaurant), $burger);

        $this->actingAs($restaurant)->delete(route('restaurant.queues.destroy', $kitchen))
            ->assertRedirect(route('restaurant.queues.index'));

        $this->assertModelMissing($kitchen);
    }

    public function test_abandoned_item_cannot_be_delivered_later(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $item = $this->queueItem($table, $this->product($restaurant), ['status' => 'abandoned']);

        $this->actingAs($restaurant)->patch(route('restaurant.queue-items.mark-delivered', $item));

        $this->assertSame(QueueItemStatus::ABANDONED, $item->fresh()->status);
        $this->assertSame(0, DB::table('restaurant_table_product')->count());
    }
}
