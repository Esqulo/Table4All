<?php

namespace Tests\Feature\Restaurant;

use App\Enums\StaffFeature;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Sold-out products ("esgotado"): who marks them, and that nobody orders or launches them. */
class ProductSoldOutTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $restaurant;

    private Product $burger;

    private RestaurantTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($this->restaurant);
        $this->burger = $this->product($this->restaurant, ['name' => 'Hambúrguer', 'price' => 30, 'queue_id' => $kitchen->id]);
        $this->table = $this->openTable($this->restaurant);
    }

    /** @param  array<string, string>  $permissions */
    private function staff(array $permissions): User
    {
        $member = $this->waiterOf($this->restaurant);
        $member->forceFill(['staff_permissions' => $permissions])->save();

        return $member->fresh() ?? $member;
    }

    private function mark(bool $soldOut, ?User $as = null, ?Product $product = null): TestResponse
    {
        return $this->actingAs($as ?? $this->restaurant)
            ->from(route('restaurant.products.index'))
            ->patch(route('restaurant.products.sold-out', $product ?? $this->burger), ['sold_out' => $soldOut]);
    }

    private function isSoldOut(?Product $product = null): bool
    {
        return Product::whereKey(($product ?? $this->burger)->id)->value('sold_out_at') !== null;
    }

    // ── marking ──────────────────────────────────────────────────────────────

    public function test_the_owner_marks_a_product_sold_out_and_back(): void
    {
        $this->mark(true)->assertRedirect(route('restaurant.products.index'));
        $this->assertTrue($this->isSoldOut());

        $this->mark(false);
        $this->assertFalse($this->isSoldOut());
    }

    public function test_marking_again_keeps_the_time_it_sold_out(): void
    {
        $this->travelTo(now()->subHour());
        $this->mark(true);
        $first = Product::whereKey($this->burger->id)->value('sold_out_at');
        $this->travelBack();

        $this->mark(true);

        $this->assertEquals($first, Product::whereKey($this->burger->id)->value('sold_out_at'));
    }

    public function test_staff_granted_availability_mark_products_without_editing_them(): void
    {
        $cook = $this->staff([StaffFeature::AVAILABILITY->value => 'write']);

        $this->actingAs($cook)->get(route('restaurant.products.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManage', false)
                ->where('canToggleSoldOut', true));

        $this->mark(true, $cook);
        $this->assertTrue($this->isSoldOut());

        // Still can't edit the product itself.
        $this->actingAs($cook)->get(route('restaurant.products.edit', $this->burger))->assertRedirect(route('dashboard'));
        $this->actingAs($cook)->delete(route('restaurant.products.destroy', $this->burger));
        $this->assertNotNull(Product::find($this->burger->id));
    }

    public function test_staff_who_write_products_mark_them_too(): void
    {
        $this->mark(true, $this->staff([StaffFeature::PRODUCTS->value => 'write']));

        $this->assertTrue($this->isSoldOut());
    }

    public function test_reading_is_not_enough_to_mark(): void
    {
        foreach ([
            [StaffFeature::AVAILABILITY->value => 'read'],
            [StaffFeature::PRODUCTS->value => 'read'],
            [],
        ] as $permissions) {
            $this->mark(true, $this->staff($permissions));
        }

        $this->assertFalse($this->isSoldOut());
        $this->actingAs($this->staff([StaffFeature::PRODUCTS->value => 'read']))->get(route('restaurant.products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canToggleSoldOut', false));
    }

    public function test_staff_without_either_feature_dont_see_the_products(): void
    {
        $this->actingAs($this->staff([StaffFeature::TABLES->value => 'write']))
            ->get(route('restaurant.products.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_another_restaurants_product_is_refused(): void
    {
        $this->mark(true, $this->restaurant())->assertForbidden();

        $this->assertFalse($this->isSoldOut());
    }

    public function test_new_waiters_can_mark_products_sold_out(): void
    {
        $this->assertSame('write', StaffFeature::waiterDefaults()[StaffFeature::AVAILABILITY->value]);
    }

    // ── ordering ─────────────────────────────────────────────────────────────

    public function test_the_customer_sees_it_sold_out_and_cant_order_it(): void
    {
        $this->burger->forceFill(['sold_out_at' => now()])->save();
        $fries = $this->product($this->restaurant, ['name' => 'Fritas']);
        $customer = $this->customer();
        $code = (string) $this->table->access_code;

        $this->actingAs($customer)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('menu', fn ($menu) => collect($menu)->firstWhere('name', 'Hambúrguer')['sold_out'] === true
                    && collect($menu)->firstWhere('name', 'Fritas')['sold_out'] === false));

        // The whole order waits, so the customer can take it out of the cart.
        $this->actingAs($customer)->post(route('customer.order', $code), [
            'items' => [
                ['product_id' => $this->burger->id, 'quantity' => 1],
                ['product_id' => $fries->id, 'quantity' => 1],
            ],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, QueueItem::count());
    }

    public function test_the_public_table_page_shows_it_sold_out(): void
    {
        $this->burger->forceFill(['sold_out_at' => now()])->save();

        $this->get(route('table.show', (string) $this->table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products', fn ($products) => collect($products)->firstWhere('name', 'Hambúrguer')['sold_out_at'] !== null));
    }

    public function test_the_waiter_cant_launch_it_to_the_kitchen(): void
    {
        $this->burger->forceFill(['sold_out_at' => now()])->save();

        $this->actingAs($this->waiterOf($this->restaurant))->patch(route('restaurant.tables.update', $this->table), [
            'products' => [],
            'queue_additions' => [['product_id' => $this->burger->id, 'price' => 30, 'quantity' => 1]],
        ])->assertInertiaFlash('toast.message', 'tables.error_sold_out');

        $this->assertSame(0, QueueItem::count());
    }

    public function test_a_sold_out_line_already_served_can_be_lowered_but_not_raised(): void
    {
        $beer = $this->product($this->restaurant, ['name' => 'Chopp', 'price' => 10]);
        $this->billLine($this->table, $beer, 3, 10.00);
        $beer->forceFill(['sold_out_at' => now()])->save();
        $waiter = $this->waiterOf($this->restaurant);
        $save = fn (int $quantity) => $this->actingAs($waiter)->patch(route('restaurant.tables.update', $this->table), [
            'products' => [['product_id' => $beer->id, 'price' => 10, 'quantity' => $quantity]],
        ]);
        $served = fn (): int => (int) DB::table('restaurant_table_product')->where('product_id', $beer->id)->sum('quantity');

        $save(4);
        $this->assertSame(3, $served());

        $save(2);
        $this->assertSame(2, $served());
    }
}
