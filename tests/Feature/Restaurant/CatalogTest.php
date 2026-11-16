<?php

namespace Tests\Feature\Restaurant;

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Products, promotions (sales) and categories. */
class CatalogTest extends TestCase
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

    /** @return array<string, mixed> */
    private function productPayload(array $overrides = []): array
    {
        return ['name' => 'Porção de fritas', 'price' => '29.90', 'price_type' => 'portion', ...$overrides];
    }

    // ── products ─────────────────────────────────────────────────────────────

    public function test_product_can_go_to_the_restaurants_own_queue(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);

        $this->actingAs($restaurant)
            ->post(route('restaurant.products.store'), $this->productPayload(['queue_id' => $kitchen->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($kitchen->id, Product::sole()->queue_id);
    }

    public function test_product_cannot_be_routed_to_another_restaurants_kitchen(): void
    {
        $restaurant = $this->restaurant();
        $foreignQueue = $this->kitchenQueue($this->restaurant());

        $this->actingAs($restaurant)
            ->post(route('restaurant.products.store'), $this->productPayload(['queue_id' => $foreignQueue->id]))
            ->assertSessionHasErrors('queue_id');

        $own = $this->product($restaurant);
        $this->actingAs($restaurant)
            ->put(route('restaurant.products.update', $own), $this->productPayload(['queue_id' => $foreignQueue->id]))
            ->assertSessionHasErrors('queue_id');
        $this->assertNull($own->fresh()->queue_id);
    }

    public function test_empty_queue_and_category_from_the_form_mean_none(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)
            ->post(route('restaurant.products.store'), $this->productPayload(['queue_id' => '', 'category_id' => '']))
            ->assertSessionHasNoErrors();

        $product = Product::sole();
        $this->assertNull($product->queue_id);
        $this->assertNull($product->category_id);
    }

    public function test_product_fields_are_validated(): void
    {
        $restaurant = $this->restaurant();

        foreach ([
            ['name' => ''],
            ['price' => '0'],
            ['price' => '-1'],
            ['price' => '100000'],
            ['price' => 'abc'],
            ['price_type' => 'dozen'],
            ['category_id' => 999],
        ] as $override) {
            $this->actingAs($restaurant)
                ->post(route('restaurant.products.store'), $this->productPayload($override))
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, Product::count());
    }

    public function test_picture_must_be_an_image(): void
    {
        Storage::fake('public');
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)
            ->post(route('restaurant.products.store'), $this->productPayload([
                'picture' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('picture');
    }

    public function test_uploaded_picture_is_stored_shrunk(): void
    {
        Storage::fake('public');
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.products.store'), $this->productPayload([
            'picture' => UploadedFile::fake()->image('foto.jpg', 2400, 1600),
        ]));

        $path = (string) Product::sole()->picture;
        Storage::disk('public')->assertExists($path);

        if (function_exists('imagecreatefromstring')) {
            [$width, $height] = getimagesizefromstring((string) Storage::disk('public')->get($path)) ?: [0, 0];
            $this->assertSame(800, $width);
            $this->assertSame(533, $height);
        }
    }

    public function test_replacing_a_picture_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $restaurant = $this->restaurant();
        Storage::disk('public')->put('products/old.jpg', 'x');
        $product = $this->product($restaurant, ['picture' => 'products/old.jpg']);

        $this->actingAs($restaurant)->put(route('restaurant.products.update', $product), $this->productPayload([
            'picture' => UploadedFile::fake()->image('nova.jpg', 300, 300),
        ]));

        Storage::disk('public')->assertMissing('products/old.jpg');
        $this->assertNotSame('products/old.jpg', $product->fresh()->picture);
    }

    public function test_deleted_product_leaves_menus_but_stays_on_open_bills(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant, ['name' => 'Chopp']);
        $table = $this->openTable($restaurant);
        $this->billLine($table, $product, 2, 10);

        $this->actingAs($restaurant)->delete(route('restaurant.products.destroy', $product));

        $this->assertSoftDeleted($product);
        $this->actingAs($restaurant)->get(route('restaurant.products.index'))
            ->assertInertia(fn (Assert $page) => $page->has('products', 0));
        $this->get(route('table.show', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page->has('products', 0));
        $this->actingAs($restaurant)->get(route('restaurant.tables.show', $table))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orderLines.0.name', 'Chopp')
                ->where('orderLines.0.quantity', 2)
            );
    }

    public function test_waiter_sees_the_catalog_read_only(): void
    {
        $restaurant = $this->restaurant();
        $this->product($restaurant);

        $this->actingAs($this->waiterOf($restaurant))->get(route('restaurant.products.index'))
            ->assertInertia(fn (Assert $page) => $page->has('products', 1)->where('canManage', false));
    }

    // ── promotions ───────────────────────────────────────────────────────────

    public function test_weekly_promotion_is_validated(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        $base = ['product_id' => $product->id, 'type' => 'periodic', 'sale_price' => 5, 'days' => [5], 'start_time' => '17:00', 'end_time' => '20:00'];

        foreach ([
            ['days' => []],
            ['days' => [7]],
            ['start_time' => null],
            ['end_time' => '16:00'],
            ['end_time' => '17:00'],
            ['start_time' => '25:00'],
            ['sale_price' => 0],
        ] as $override) {
            $this->actingAs($restaurant)->post(route('restaurant.sales.store'), [...$base, ...$override])->assertSessionHasErrors();
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_dated_promotion_is_validated(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        $base = ['product_id' => $product->id, 'type' => 'scheduled', 'sale_price' => 5, 'starts_at' => '2026-12-24 18:00', 'ends_at' => '2026-12-25 02:00'];

        $this->actingAs($restaurant)->post(route('restaurant.sales.store'), [...$base, 'ends_at' => '2026-12-24 17:00'])->assertSessionHasErrors('ends_at');
        $this->actingAs($restaurant)->post(route('restaurant.sales.store'), [...$base, 'starts_at' => null])->assertSessionHasErrors('starts_at');
        $this->actingAs($restaurant)->post(route('restaurant.sales.store'), [...$base, 'type' => 'forever'])->assertSessionHasErrors('type');
        $this->assertSame(0, Sale::count());
    }

    public function test_promotion_only_for_the_restaurants_own_live_products(): void
    {
        $restaurant = $this->restaurant();
        $foreign = $this->product($this->restaurant());
        $deleted = $this->product($restaurant);
        $deleted->delete();

        foreach ([$foreign, $deleted] as $product) {
            $this->actingAs($restaurant)->post(route('restaurant.sales.store'), [
                'product_id' => $product->id, 'type' => 'periodic', 'sale_price' => 5,
                'days' => [1], 'start_time' => '17:00', 'end_time' => '20:00',
            ])->assertSessionHasErrors('product_id');
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_switching_promotion_type_clears_the_other_types_fields(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        $sale = Sale::create([
            'user_id' => $restaurant->id, 'product_id' => $product->id, 'type' => 'periodic',
            'sale_price' => 5, 'days' => [1, 2], 'start_time' => '17:00', 'end_time' => '20:00',
        ]);

        $this->actingAs($restaurant)->put(route('restaurant.sales.update', $sale), [
            'product_id' => $product->id, 'type' => 'scheduled', 'sale_price' => 6,
            'starts_at' => '2026-12-24 18:00', 'ends_at' => '2026-12-25 02:00',
        ])->assertSessionHasNoErrors()->assertRedirect(route('restaurant.sales.index'));

        $sale->refresh();
        $this->assertNull($sale->days);
        $this->assertNull($sale->start_time);
        $this->assertNull($sale->end_time);
        $this->assertNotNull($sale->starts_at);
    }

    public function test_weekly_promotion_follows_brasilia_time_not_utc(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        Sale::create([
            'user_id' => $restaurant->id, 'product_id' => $product->id, 'type' => 'periodic',
            'sale_price' => 7, 'days' => [5], 'start_time' => '17:00', 'end_time' => '20:00', // Fridays
        ]);

        // Friday 2026-11-20 17:30 in Brasilia = 20:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-20 20:30:00', 'UTC'));
        $this->assertEquals(7.0, Sale::activePriceMap($restaurant->id)[$product->id] ?? null);

        // 14:30 in Brasilia (17:30 UTC): the window hasn't started yet.
        Carbon::setTestNow(Carbon::parse('2026-11-20 17:30:00', 'UTC'));
        $this->assertArrayNotHasKey($product->id, Sale::activePriceMap($restaurant->id)->all());
    }

    public function test_weekday_is_the_local_one_late_at_night(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        Sale::create([
            'user_id' => $restaurant->id, 'product_id' => $product->id, 'type' => 'periodic',
            'sale_price' => 7, 'days' => [5], 'start_time' => '21:00', 'end_time' => '23:30', // Friday nights
        ]);

        // Saturday 01:00 UTC is still Friday 22:00 in Brasilia.
        Carbon::setTestNow(Carbon::parse('2026-11-21 01:00:00', 'UTC'));

        $this->assertEquals(7.0, Sale::activePriceMap($restaurant->id)[$product->id] ?? null);
    }

    public function test_dated_promotion_window_is_inclusive_and_local(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        Sale::create([
            'user_id' => $restaurant->id, 'product_id' => $product->id, 'type' => 'scheduled',
            'sale_price' => 6, 'starts_at' => '2026-12-24 18:00:00', 'ends_at' => '2026-12-24 22:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-12-24 18:00:00', 'America/Sao_Paulo'));
        $this->assertTrue(Sale::activePriceMap($restaurant->id)->has($product->id));

        Carbon::setTestNow(Carbon::parse('2026-12-24 22:00:01', 'America/Sao_Paulo'));
        $this->assertFalse(Sale::activePriceMap($restaurant->id)->has($product->id));
    }

    public function test_promotions_page_survives_a_deleted_product(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant, ['name' => 'Chopp']);
        Sale::create([
            'user_id' => $restaurant->id, 'product_id' => $product->id, 'type' => 'periodic',
            'sale_price' => 5, 'days' => [1], 'start_time' => '17:00', 'end_time' => '20:00',
        ]);
        $product->delete();

        $this->actingAs($restaurant)->get(route('restaurant.sales.index'))
            ->assertInertia(fn (Assert $page) => $page->where('sales.0.product.name', 'Chopp'));
    }

    // ── categories (admin) ───────────────────────────────────────────────────

    public function test_category_names_are_unique(): void
    {
        $admin = $this->admin();
        Category::create(['name' => 'Bebidas']);

        $this->actingAs($admin)->post(route('admin.categories.store'), ['name' => 'Bebidas'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('admin.categories.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->assertSame(1, Category::count());
    }

    public function test_deleting_a_category_keeps_its_products_uncategorised(): void
    {
        $category = Category::create(['name' => 'Bebidas']);
        $product = $this->product($this->restaurant(), ['category_id' => $category->id]);

        $this->actingAs($this->admin())->delete(route('admin.categories.destroy', $category));

        $this->assertModelMissing($category);
        $this->assertNull($product->fresh()->category_id);
    }
}
