<?php

namespace Tests\Feature\Restaurant;

use App\Http\Middleware\ThrottleTableCodes;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * What the browser sends is never trusted for money or ownership: prices come from the
 * server, a closed bill stays closed, staff act only within their restaurant, and table
 * codes can't be found by trying them in a row.
 */
class UntrustedInputTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $restaurant;

    private RestaurantTable $table;

    private Product $water;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->restaurant = $this->restaurant();
        $this->table = $this->openTable($this->restaurant);
        $this->water = $this->product($this->restaurant, ['name' => 'Água', 'price' => 6]);
    }

    /** @param  array<string, mixed>  $payload */
    private function saveOrder(array $payload, ?User $as = null): void
    {
        $this->actingAs($as ?? $this->restaurant)
            ->patch(route('restaurant.tables.update', $this->table), $payload);
    }

    // ── prices on the waiter's order ─────────────────────────────────────────

    public function test_a_new_line_is_billed_at_the_product_price_not_the_one_sent(): void
    {
        $this->saveOrder(['products' => [['product_id' => $this->water->id, 'price' => 0.01, 'quantity' => 2]]]);

        $line = DB::table('restaurant_table_product')->sole();
        $this->assertSame(6.0, (float) $line->price);
        $this->assertSame(2, (int) $line->quantity);
    }

    public function test_a_new_line_gets_the_active_promotion_price(): void
    {
        Sale::create([
            'user_id' => $this->restaurant->id,
            'product_id' => $this->water->id,
            'type' => 'scheduled',
            'sale_price' => 4,
            // Wall-clock times of the venue; a day each side keeps it active in any timezone.
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->saveOrder(['products' => [['product_id' => $this->water->id, 'price' => 6, 'quantity' => 1]]]);

        $this->assertSame(4.0, (float) DB::table('restaurant_table_product')->value('price'));
    }

    public function test_a_line_already_on_the_bill_keeps_its_price_when_its_quantity_changes(): void
    {
        // Ordered when water cost 5; it costs 6 now.
        $this->billLine($this->table, $this->water, 1, 5);

        $this->saveOrder(['products' => [['product_id' => $this->water->id, 'price' => 5, 'quantity' => 3]]]);

        $line = DB::table('restaurant_table_product')->sole();
        $this->assertSame(5.0, (float) $line->price);
        $this->assertSame(3, (int) $line->quantity);
    }

    public function test_a_made_up_price_next_to_an_existing_line_adds_units_at_the_current_price(): void
    {
        $this->billLine($this->table, $this->water, 1, 6);

        $this->saveOrder(['products' => [
            ['product_id' => $this->water->id, 'price' => 6, 'quantity' => 1],
            ['product_id' => $this->water->id, 'price' => 0.5, 'quantity' => 2],
        ]]);

        $line = DB::table('restaurant_table_product')->sole();
        $this->assertSame(6.0, (float) $line->price);
        $this->assertSame(3, (int) $line->quantity);
    }

    public function test_items_sent_to_the_kitchen_are_priced_by_the_server(): void
    {
        $kitchen = $this->kitchenQueue($this->restaurant);
        $burger = $this->product($this->restaurant, ['name' => 'Burger', 'price' => 30, 'queue_id' => $kitchen->id]);

        $this->saveOrder([
            'products' => [],
            'queue_additions' => [['product_id' => $burger->id, 'price' => 0.01, 'quantity' => 2]],
        ]);

        $item = QueueItem::sole();
        $this->assertSame(30.0, (float) $item->price);
        $this->assertSame(2, $item->quantity);
    }

    public function test_another_restaurants_product_is_ignored(): void
    {
        $foreign = $this->product($this->restaurant(), ['price' => 1]);

        $this->saveOrder(['products' => [['product_id' => $foreign->id, 'price' => 1, 'quantity' => 5]]]);

        $this->assertSame(0, DB::table('restaurant_table_product')->count());
    }

    // ── closed bills ─────────────────────────────────────────────────────────

    public function test_a_closed_tables_order_cant_be_changed(): void
    {
        $this->billLine($this->table, $this->water, 2, 6);
        $this->table->forceFill(['closed_at' => now()])->save();

        $this->saveOrder(['products' => [['product_id' => $this->water->id, 'price' => 6, 'quantity' => 9]]]);

        $this->assertSame(2, (int) DB::table('restaurant_table_product')->value('quantity'));
        $this->assertSame(0, QueueItem::count());
    }

    // ── staff act for their restaurant ───────────────────────────────────────

    /** @param  array<string, string>  $permissions */
    private function staff(array $permissions): User
    {
        $member = $this->waiterOf($this->restaurant);
        $member->forceFill(['staff_permissions' => $permissions])->save();

        return $member->fresh();
    }

    public function test_staff_create_products_on_their_restaurants_queue_but_not_another_ones(): void
    {
        $staff = $this->staff(['products' => 'write']);
        $own = $this->kitchenQueue($this->restaurant);
        $foreign = $this->kitchenQueue($this->restaurant(), 'Outra cozinha');
        $product = ['name' => 'Pastel', 'price' => 12, 'price_type' => 'unit'];

        $this->actingAs($staff)->post(route('restaurant.products.store'), [...$product, 'queue_id' => $foreign->id])
            ->assertSessionHasErrors('queue_id');
        $this->actingAs($staff)->post(route('restaurant.products.store'), [...$product, 'queue_id' => $own->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($own->id, Product::where('name', 'Pastel')->sole()->queue_id);
        $this->assertSame($this->restaurant->id, Product::where('name', 'Pastel')->sole()->user_id);
    }

    public function test_staff_create_promotions_only_for_their_restaurants_products(): void
    {
        $staff = $this->staff(['sales' => 'write']);
        $foreign = $this->product($this->restaurant());
        $sale = ['type' => 'periodic', 'sale_price' => 3, 'days' => [1, 2], 'start_time' => '18:00', 'end_time' => '20:00'];

        $this->actingAs($staff)->post(route('restaurant.sales.store'), [...$sale, 'product_id' => $foreign->id])
            ->assertSessionHasErrors('product_id');
        $this->actingAs($staff)->post(route('restaurant.sales.store'), [...$sale, 'product_id' => $this->water->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->restaurant->id, Sale::sole()->user_id);
    }

    public function test_staff_cant_create_a_coupon_code_their_restaurant_already_has(): void
    {
        $staff = $this->staff(['coupons' => 'write']);
        $coupon = ['code' => 'BEMVINDO', 'discount_type' => 'percent', 'discount_value' => 10, 'multiple_per_table' => false];

        $this->actingAs($this->restaurant)->post(route('restaurant.coupons.store'), $coupon)->assertSessionHasNoErrors();
        $this->actingAs($staff)->post(route('restaurant.coupons.store'), $coupon)->assertSessionHasErrors('code');

        $this->assertSame(1, Coupon::count());
    }

    // ── table codes can't be guessed ─────────────────────────────────────────

    private function wrongCode(int $i): string
    {
        // Six digits that match no open table.
        return str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT);
    }

    public function test_wrong_table_codes_are_limited_per_account(): void
    {
        $guesser = $this->customer();

        for ($i = 0; $i < ThrottleTableCodes::MAX_MISSES_PER_USER; $i++) {
            $this->actingAs($guesser)->get(route('customer.table', $this->wrongCode($i)))
                ->assertRedirect(route('customer.join'));
        }

        // Blocked now, even for a code that exists: guessing it right no longer helps.
        $this->actingAs($guesser)->get(route('customer.table', $this->table->access_code))->assertStatus(429);
        $this->assertNull($this->table->fresh()->responsible_user_id);

        // Someone else is not affected.
        $this->actingAs($this->customer())->get(route('customer.table', $this->table->access_code))->assertOk();
    }

    public function test_ordering_with_wrong_codes_counts_too(): void
    {
        $guesser = $this->customer();

        for ($i = 0; $i < ThrottleTableCodes::MAX_MISSES_PER_USER; $i++) {
            $this->actingAs($guesser)
                ->post(route('customer.order', $this->wrongCode($i)), ['items' => [['product_id' => $this->water->id, 'quantity' => 1]]])
                ->assertNotFound();
        }

        $this->actingAs($guesser)
            ->post(route('customer.order', $this->table->access_code), ['items' => [['product_id' => $this->water->id, 'quantity' => 1]]])
            ->assertStatus(429);
        $this->assertSame(0, QueueItem::count());
        $this->assertNull($this->table->fresh()->responsible_user_id);
    }

    public function test_a_customer_at_a_real_table_is_never_limited(): void
    {
        $customer = $this->customer();

        for ($i = 0; $i < ThrottleTableCodes::MAX_MISSES_PER_USER * 2; $i++) {
            $this->actingAs($customer)->get(route('customer.table', $this->table->access_code))->assertOk();
        }
    }

    public function test_the_public_table_page_limits_wrong_codes_per_ip(): void
    {
        for ($i = 0; $i < ThrottleTableCodes::MAX_MISSES_PER_IP; $i++) {
            $this->get(route('table.show', $this->wrongCode($i)))->assertRedirect(route('table.join'));
        }

        $this->get(route('table.show', $this->table->access_code))->assertStatus(429);
    }
}
