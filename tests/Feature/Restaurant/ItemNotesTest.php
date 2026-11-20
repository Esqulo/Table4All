<?php

namespace Tests\Feature\Restaurant;

use App\Enums\QueueItemStatus;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantQueue;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Notes on ordered items ("sem cebola", "ao ponto"): written when launching, shown to the kitchen. */
class ItemNotesTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $restaurant;

    private RestaurantQueue $kitchen;

    private Product $burger;

    private RestaurantTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->restaurant = $this->restaurant();
        $this->kitchen = $this->kitchenQueue($this->restaurant);
        $this->burger = $this->product($this->restaurant, ['name' => 'Hambúrguer', 'price' => 30, 'queue_id' => $this->kitchen->id]);
        $this->table = $this->openTable($this->restaurant);
    }

    /** @param  list<array<string, mixed>>  $additions */
    private function launch(array $additions, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->waiterOf($this->restaurant))
            ->patch(route('restaurant.tables.update', $this->table), [
                'products' => [],
                'queue_additions' => $additions,
            ]);
    }

    public function test_the_waiter_launches_the_same_item_with_different_notes(): void
    {
        $this->launch([
            ['product_id' => $this->burger->id, 'price' => 30, 'quantity' => 2, 'notes' => 'ao ponto'],
            ['product_id' => $this->burger->id, 'price' => 30, 'quantity' => 1, 'notes' => 'mal passado, sem cebola'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            [[2, 'ao ponto'], [1, 'mal passado, sem cebola']],
            QueueItem::orderBy('id')->get(['quantity', 'notes'])->map(fn (QueueItem $i) => [$i->quantity, $i->notes])->all(),
        );
    }

    public function test_notes_are_kept_on_one_line_and_an_empty_note_is_none(): void
    {
        $this->launch([
            ['product_id' => $this->burger->id, 'price' => 30, 'quantity' => 1, 'notes' => "  sem\n  cebola  "],
            ['product_id' => $this->burger->id, 'price' => 30, 'quantity' => 1, 'notes' => '   '],
            ['product_id' => $this->burger->id, 'price' => 30, 'quantity' => 1],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['sem cebola', null, null], QueueItem::orderBy('id')->pluck('notes')->all());
    }

    public function test_a_note_too_long_is_refused(): void
    {
        $this->launch([
            ['product_id' => $this->burger->id, 'price' => 30, 'quantity' => 1, 'notes' => str_repeat('a', QueueItem::NOTES_MAX + 1)],
        ])->assertSessionHasErrors('queue_additions.0.notes');

        $this->assertSame(0, QueueItem::count());
    }

    public function test_the_kitchen_the_delivery_board_and_the_table_page_show_the_note(): void
    {
        $this->queueItem($this->table, $this->burger, ['notes' => 'sem cebola']);
        $this->queueItem($this->table, $this->burger, ['notes' => 'ao ponto', 'status' => QueueItemStatus::DONE->value]);

        // Both were ordered at the same instant: compare the notes, not their order.
        $notes = fn (mixed $items): array => collect($items)->pluck('notes')->sort()->values()->all();

        $this->actingAs($this->restaurant)->get(route('restaurant.queues.show', $this->kitchen))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($items) => $notes($items) === ['ao ponto', 'sem cebola']));

        $this->actingAs($this->restaurant)->get(route('restaurant.delivery.index'))
            ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.notes', 'ao ponto'));

        $this->actingAs($this->restaurant)->get(route('restaurant.tables.show', $this->table))
            ->assertInertia(fn (Assert $page) => $page
                ->where('queueItems', fn ($items) => $notes($items) === ['ao ponto', 'sem cebola']));
    }

    public function test_the_customer_adds_a_note_and_sees_it_while_it_is_prepared(): void
    {
        $customer = $this->customer();
        $url = route('customer.table', (string) $this->table->access_code);
        $this->actingAs($customer)->get($url)->assertOk();

        $this->actingAs($customer)->post(route('customer.order', (string) $this->table->access_code), [
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1, 'notes' => 'sem picles']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('sem picles', QueueItem::value('notes'));
        $this->actingAs($customer)->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('preparing.0.notes', 'sem picles'));
    }

    public function test_a_customer_note_too_long_is_refused(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer)->get(route('customer.table', (string) $this->table->access_code))->assertOk();

        $this->actingAs($customer)->post(route('customer.order', (string) $this->table->access_code), [
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1, 'notes' => str_repeat('a', QueueItem::NOTES_MAX + 1)]],
        ])->assertSessionHasErrors('items.0.notes');

        $this->assertSame(0, QueueItem::count());
    }
}
