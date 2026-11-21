<?php

namespace Tests\Feature\Restaurant;

use App\Models\Category;
use App\Models\Menu;
use App\Models\QueueItem;
use App\Models\RestaurantQueue;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * Who can reach what: the middleware groups in routes/web.php and the manual
 * ownership checks (abort_unless ... effectiveRestaurantId()) in controllers.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_sent_to_login_from_staff_and_customer_routes(): void
    {
        foreach (['restaurant.tables.index', 'restaurant.delivery.index', 'restaurant.queues.index', 'customer.join'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_waiter_reaches_the_service_screens(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);
        $table = $this->openTable($restaurant);

        foreach ([
            route('restaurant.tables.index'),
            route('restaurant.tables.show', $table),
            route('restaurant.delivery.index'),
            route('restaurant.products.index'),
        ] as $url) {
            $this->actingAs($waiter)->get($url)->assertOk();
        }
    }

    public function test_waiter_is_kept_out_of_management_screens(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);

        foreach ([
            route('restaurant.products.create'),
            route('restaurant.queues.index'),
            route('restaurant.sales.index'),
            route('restaurant.waiters.index'),
            route('restaurant.menus.index'),
            route('restaurant.dashboards.index'),
        ] as $url) {
            $this->actingAs($waiter)->get($url)->assertRedirect(route('dashboard'));
        }
    }

    public function test_customer_is_kept_out_of_staff_screens(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->get(route('restaurant.tables.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($customer)->get(route('restaurant.queues.index'))->assertRedirect(route('dashboard'));
    }

    public function test_waiter_removed_from_the_restaurant_loses_access(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);

        $this->actingAs($restaurant)->delete(route('restaurant.waiters.destroy', $waiter));

        $this->actingAs($waiter->fresh())->get(route('restaurant.tables.index'))->assertRedirect(route('dashboard'));
    }

    public function test_only_admins_reach_the_category_panel(): void
    {
        $this->actingAs($this->restaurant())->get(route('admin.categories.index'))->assertForbidden();
        $this->actingAs($this->customer())->post(route('admin.categories.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.categories.index'))->assertOk();
    }

    public function test_staff_cannot_touch_another_restaurants_table(): void
    {
        $owner = $this->restaurant();
        $table = $this->openTable($owner);
        $intruders = [$this->restaurant(), $this->waiterOf($this->restaurant())];

        foreach ($intruders as $intruder) {
            $this->actingAs($intruder)->get(route('restaurant.tables.show', $table))->assertForbidden();
            $this->actingAs($intruder)->get(route('restaurant.tables.edit', $table))->assertForbidden();
            $this->actingAs($intruder)->patch(route('restaurant.tables.update', $table), ['title' => 'x'])->assertForbidden();
            $this->actingAs($intruder)->post(route('restaurant.tables.add-payment', $table), ['method' => 'cash', 'amount' => 1])->assertForbidden();
            $this->actingAs($intruder)->patch(route('restaurant.tables.close', $table))->assertForbidden();
            $this->actingAs($intruder)->delete(route('restaurant.tables.destroy', $table))->assertForbidden();
        }

        $this->assertNotSoftDeleted($table);
        $this->assertSame(0, $table->payments()->count());
    }

    public function test_restaurant_cannot_touch_another_restaurants_catalog(): void
    {
        $owner = $this->restaurant();
        $intruder = $this->restaurant();
        $product = $this->product($owner);
        $queue = RestaurantQueue::create(['user_id' => $owner->id, 'name' => 'Bar']);
        $menu = Menu::create(['user_id' => $owner->id, 'name' => 'Principal']);
        $sale = Sale::create([
            'user_id' => $owner->id, 'product_id' => $product->id, 'type' => 'periodic',
            'sale_price' => 5, 'days' => [1], 'start_time' => '17:00', 'end_time' => '19:00',
        ]);

        $this->actingAs($intruder)->get(route('restaurant.products.edit', $product))->assertForbidden();
        $this->actingAs($intruder)->delete(route('restaurant.products.destroy', $product))->assertForbidden();
        $this->actingAs($intruder)->get(route('restaurant.queues.show', $queue))->assertForbidden();
        $this->actingAs($intruder)->delete(route('restaurant.queues.destroy', $queue))->assertForbidden();
        $this->actingAs($intruder)->get(route('restaurant.menus.print', $menu))->assertForbidden();
        $this->actingAs($intruder)->delete(route('restaurant.sales.destroy', $sale))->assertForbidden();

        $this->assertNotSoftDeleted($product);
        $this->assertModelExists($queue);
        $this->assertModelExists($sale);
    }

    public function test_categories_are_global_but_only_admins_manage_them(): void
    {
        $category = Category::create(['name' => 'Bebidas']);

        $this->actingAs($this->restaurant())->delete(route('admin.categories.destroy', $category))->assertForbidden();

        $this->assertModelExists($category);
    }

    // ── email verification ───────────────────────────────────────────────────

    public function test_unverified_accounts_are_sent_to_verify_their_email(): void
    {
        $restaurant = $this->restaurant(['email_verified_at' => null]);
        $customer = User::factory()->unverified()->create();
        $owner = $this->restaurant();
        $table = $this->openTable($owner);

        $this->actingAs($restaurant)->get(route('restaurant.tables.index'))->assertRedirect(route('verification.notice'));
        $this->actingAs($customer)->get(route('customer.table', $table->access_code))->assertRedirect(route('verification.notice'));
        $this->actingAs($customer)->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $this->product($owner)->id, 'quantity' => 1]],
        ])->assertRedirect(route('verification.notice'));

        $this->assertSame(0, QueueItem::count());
    }

    public function test_after_verifying_the_customer_returns_to_their_table(): void
    {
        $customer = User::factory()->unverified()->create();
        $table = $this->openTable($this->restaurant());
        $this->actingAs($customer)->get(route('customer.table', $table->access_code));

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $customer->id, 'hash' => sha1($customer->email),
        ]);

        $this->actingAs($customer)->get($link)
            ->assertRedirect(route('customer.table', $table->access_code));
        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
    }

    public function test_registration_sends_the_verification_email(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Cliente', 'email' => 'cliente@example.com', 'phone' => '11999999999',
            'password' => 'password', 'password_confirmation' => 'password', 'terms' => '1',
        ]);

        $user = User::where('email', 'cliente@example.com')->sole();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
