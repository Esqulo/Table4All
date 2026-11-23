<?php

namespace Tests\Feature\Restaurant;

use App\Models\Menu;
use App\Models\User;
use App\Support\Privacy\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Admins switching accounts off (and back on), and what that does to a restaurant. */
class AccountDeactivationTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private function deactivate(User $user, ?User $admin = null, string $reason = ''): void
    {
        $this->actingAs($admin ?? $this->admin())
            ->patch(route('admin.users.deactivate', $user), ['reason' => $reason])
            ->assertRedirect();
        auth()->logout();
    }

    // ─── The users list ───────────────────────────────────────────────────────

    public function test_only_admins_reach_the_users_list_and_the_switches(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();

        foreach ([$restaurant, $this->waiterOf($restaurant), $customer] as $user) {
            $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.users.deactivate', $customer))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.users.reactivate', $customer))->assertForbidden();
        }

        $this->assertNull($customer->refresh()->deactivated_at);
    }

    public function test_admin_lists_accounts_with_filters(): void
    {
        $admin = $this->admin();
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $waiter = $this->waiterOf($restaurant);
        $customer = User::factory()->create(['name' => 'Ana Cliente', 'email' => 'ana@example.com']);
        $this->deactivate($customer, $admin, 'Fraude no pagamento');

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users')
                ->where('users.total', 4)
                ->has('users.data', 4));

        $this->actingAs($admin)->get(route('admin.users.index', ['type' => 'waiter']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $waiter->id)
                ->where('users.data.0.restaurant.name', 'Bar do Zé')
                ->where('users.data.0.staff_role', 'waiter'));

        $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'deactivated']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $customer->id)
                ->where('users.data.0.deactivated_by', $admin->name)
                ->where('users.data.0.deactivation_reason', 'Fraude no pagamento'));

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'ANA@example']))
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.id', $customer->id));

        // Admins are listed, but can't be switched off.
        $this->actingAs($admin)->get(route('admin.users.index', ['type' => 'admin']))
            ->assertInertia(fn (Assert $page) => $page->where('users.data.0.can_deactivate', false));

        // Unknown filters are ignored.
        $this->actingAs($admin)->get(route('admin.users.index', ['type' => 'x', 'status' => 'y']))
            ->assertInertia(fn (Assert $page) => $page->where('type', '')->where('status', '')->has('users.data', 4));
    }

    public function test_admins_cannot_be_deactivated(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)->patch(route('admin.users.deactivate', $other))->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.users.deactivate', $admin))->assertForbidden();

        $this->assertNull($other->refresh()->deactivated_at);
    }

    public function test_the_reason_is_limited(): void
    {
        $this->actingAs($this->admin())
            ->patch(route('admin.users.deactivate', $customer = $this->customer()), ['reason' => str_repeat('a', 501)])
            ->assertSessionHasErrors('reason');

        $this->assertNull($customer->refresh()->deactivated_at);
    }

    // ─── A deactivated account ───────────────────────────────────────────────

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $customer = $this->customer();
        $this->deactivate($customer);

        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('admin.account_deactivated')]);
        $this->assertGuest();

        // A wrong password still gets the usual answer: nothing given away.
        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    public function test_an_open_session_is_signed_out_on_the_next_request(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($customer)->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('admin.account_deactivated'));
        $this->assertGuest();
    }

    public function test_deactivating_drops_sessions_and_keeps_the_first_date_on_retry(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $customer->id, 'payload' => '', 'last_activity' => time()]);

        $this->deactivate($customer, $admin, 'primeiro');
        $first = $customer->refresh()->deactivated_at;
        $this->travel(5)->minutes();
        $this->deactivate($customer, $admin, 'segundo');

        $customer->refresh();
        $this->assertTrue($first->equalTo($customer->deactivated_at));
        $this->assertSame('primeiro', $customer->deactivation_reason);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $customer->id)->count());
    }

    public function test_reactivating_restores_access(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $this->deactivate($customer, $admin, 'engano');

        $this->actingAs($admin)->patch(route('admin.users.reactivate', $customer))->assertRedirect();
        auth()->logout();

        $customer->refresh();
        $this->assertNull($customer->deactivated_at);
        $this->assertNull($customer->deactivated_by);
        $this->assertNull($customer->deactivation_reason);

        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($customer);
    }

    // ─── A deactivated restaurant ─────────────────────────────────────────────

    public function test_a_deactivated_restaurant_keeps_its_team_out(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);
        $other = $this->waiterOf($this->restaurant());
        DB::table('sessions')->insert(['id' => 'w1', 'user_id' => $waiter->id, 'payload' => '', 'last_activity' => time()]);

        $this->deactivate($restaurant);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $waiter->id)->count());

        $this->post(route('login.store'), ['email' => $waiter->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($waiter)->get(route('restaurant.tables.index'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Another restaurant's team is untouched.
        $this->actingAs($other)->get(route('restaurant.tables.index'))->assertOk();
    }

    public function test_a_deactivated_restaurant_disappears_for_customers(): void
    {
        $restaurant = $this->restaurant(['profile_public' => true]);
        $table = $this->openTable($restaurant);
        $menu = Menu::create(['user_id' => $restaurant->id, 'name' => 'Cardápio']);
        $product = $this->product($restaurant);
        $customer = $this->customer();

        $this->get(route('restaurant-profile.show', $restaurant->handle))->assertOk();
        $this->get(route('menu.show', $menu))->assertOk();

        $this->deactivate($restaurant);

        $this->get(route('restaurant-profile.show', $restaurant->handle))->assertNotFound();
        $this->get(route('menu.show', $menu))->assertNotFound();
        $this->get(route('table.show', $table->access_code))->assertRedirect(route('table.join'));

        $this->actingAs($customer)->get(route('customer.table', $table->access_code))
            ->assertRedirect(route('customer.join'));
        $this->actingAs($customer)->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertNotFound();
        $this->assertDatabaseMissing('queue_items', ['restaurant_table_id' => $table->id]);

        $cards = $this->actingAs($customer)->get(route('social.discover'))
            ->assertOk()->viewData('page')['props']['discover']['data'];
        $this->assertNotContains($restaurant->id, array_column($cards, 'id'));

        // Back on: everything is where it was.
        $this->actingAs($this->admin())->patch(route('admin.users.reactivate', $restaurant));
        auth()->logout();
        $this->get(route('restaurant-profile.show', $restaurant->handle))->assertOk();
        $this->actingAs($customer)->get(route('customer.table', $table->access_code))->assertOk();
    }

    public function test_admin_restaurant_screens_show_the_status(): void
    {
        $admin = $this->admin();
        $restaurant = $this->restaurant();
        $this->deactivate($restaurant, $admin, 'Inadimplente');

        $this->actingAs($admin)->get(route('admin.restaurants.index'))
            ->assertInertia(fn (Assert $page) => $page->where('restaurants.data.0.deactivated', true));

        $this->actingAs($admin)->get(route('admin.restaurants.show', $restaurant))
            ->assertInertia(fn (Assert $page) => $page
                ->where('restaurant.deactivation_reason', 'Inadimplente')
                ->where('restaurant.deactivated_by', $admin->name)
                ->whereNot('restaurant.deactivated_at', null));
    }

    public function test_the_personal_data_export_mentions_the_deactivation(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['deactivated_at' => now(), 'deactivation_reason' => 'Spam'])->save();

        $export = PersonalDataExport::for($customer->refresh());

        $this->assertSame('Spam', $export['account']['deactivation_reason']);
        $this->assertNotNull($export['account']['deactivated_at']);
    }
}
