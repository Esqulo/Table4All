<?php

namespace Tests\Feature\Restaurant;

use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** A customer's one-time QR, scanned by staff opening a table, links the table to them. */
class CustomerLinkTest extends TestCase
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

    private function issueToken(User $customer): string
    {
        $this->actingAs($customer)->post(route('customer.link.create'))->assertRedirect(route('customer.join'));

        return (string) DB::table('customer_link_tokens')->where('user_id', $customer->id)->latest('id')->value('token');
    }

    public function test_customer_generates_a_one_minute_qr(): void
    {
        Carbon::setTestNow(now()->startOfSecond());
        $customer = $this->customer();
        $token = $this->issueToken($customer);

        $this->actingAs($customer)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('link.payload', 't4a-customer:'.$token)
                ->where('link.expires_in', 60)
                ->where('link.table_code', null));
    }

    public function test_a_new_qr_replaces_the_unused_one(): void
    {
        $customer = $this->customer();
        $old = $this->issueToken($customer);
        $new = $this->issueToken($customer);

        $this->assertNotSame($old, $new);
        $this->assertDatabaseCount('customer_link_tokens', 1);

        $this->actingAs($this->restaurant())
            ->post(route('restaurant.tables.store'), ['title' => 'Mesa 1', 'customer_token' => $old])
            ->assertSessionHasErrors('customer_token');
    }

    public function test_a_cancelled_qr_is_hidden_and_stops_working(): void
    {
        $customer = $this->customer();
        $token = $this->issueToken($customer);

        $this->actingAs($customer)->post(route('customer.link.cancel'))->assertRedirect(route('customer.join'));

        $this->actingAs($customer)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page->where('link', null));
        $this->actingAs($this->restaurant())
            ->post(route('restaurant.tables.store'), ['title' => 'Mesa 1', 'customer_token' => $token])
            ->assertSessionHasErrors('customer_token');
        $this->assertSame(0, RestaurantTable::count());
    }

    public function test_cancelling_keeps_the_record_of_a_qr_already_used(): void
    {
        $customer = $this->customer();
        $token = $this->issueToken($customer);
        $this->actingAs($this->restaurant())->post(route('restaurant.tables.store'), ['title' => 'Mesa 1', 'customer_token' => $token]);

        $this->actingAs($customer)->post(route('customer.link.cancel'));

        $this->assertDatabaseHas('customer_link_tokens', ['token' => $token]);
        $this->assertNotNull(DB::table('customer_link_tokens')->where('token', $token)->value('used_at'));
    }

    public function test_staff_opening_a_table_with_the_customers_qr_links_it_to_them(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $customer = $this->customer();

        foreach ([$restaurant, $this->waiterOf($restaurant)] as $i => $staff) {
            $token = $this->issueToken($customer);

            $this->actingAs($staff)
                ->post(route('restaurant.tables.store'), ['title' => "Varanda {$i}", 'customer_token' => $token])
                ->assertRedirect(route('restaurant.tables.index'))
                ->assertSessionHasNoErrors();

            $table = RestaurantTable::where('title', "Varanda {$i}")->sole();
            $this->assertSame($restaurant->id, $table->user_id);
            $this->assertSame($customer->id, $table->responsible_user_id);

            // The customer's screen follows the QR to the table, which is now in their account.
            $this->actingAs($customer)->get(route('customer.join'))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('link.table_code', $table->access_code)
                    ->where('link.expires_in', 0)
                    ->where('tables.0', [
                        'code' => $table->access_code,
                        'title' => "Varanda {$i}",
                        'restaurant' => 'Bar do Zé',
                        'status' => 'approved',
                    ]));
            $this->actingAs($customer)->get(route('customer.table', $table->access_code))
                ->assertInertia(fn (Assert $page) => $page
                    ->component('customer/show')
                    ->where('isResponsible', true));
        }
    }

    public function test_others_arriving_at_a_linked_table_need_the_customers_approval(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $token = $this->issueToken($customer);
        $this->actingAs($restaurant)->post(route('restaurant.tables.store'), ['title' => 'Mesa 1', 'customer_token' => $token]);
        $table = RestaurantTable::sole();

        $this->actingAs($this->customer())->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page->component('customer/waiting'));
    }

    public function test_a_qr_works_only_once(): void
    {
        $restaurant = $this->restaurant();
        $token = $this->issueToken($this->customer());

        $this->actingAs($restaurant)->post(route('restaurant.tables.store'), ['title' => 'Mesa 1', 'customer_token' => $token])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->restaurant())->post(route('restaurant.tables.store'), ['title' => 'Mesa 2', 'customer_token' => $token])
            ->assertSessionHasErrors('customer_token');

        $this->assertSame(['Mesa 1'], RestaurantTable::pluck('title')->all());
    }

    public function test_an_expired_or_unknown_qr_opens_no_table(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $token = $this->issueToken($customer);

        Carbon::setTestNow(now()->addSeconds(61));

        foreach ([$token, 'not-a-token', str_repeat('a', 40)] as $code) {
            $this->actingAs($restaurant)
                ->post(route('restaurant.tables.store'), ['title' => 'Mesa 1', 'customer_token' => $code])
                ->assertSessionHasErrors('customer_token');
        }

        $this->assertSame(0, RestaurantTable::count());
        $this->actingAs($customer)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('link.expires_in', 0)
                ->where('link.table_code', null));
    }

    public function test_an_old_qr_drops_off_the_customers_screen(): void
    {
        $customer = $this->customer();
        $this->issueToken($customer);

        Carbon::setTestNow(now()->addMinutes(3));

        $this->actingAs($customer)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page->where('link', null));
    }

    public function test_tables_can_still_be_opened_without_a_customer(): void
    {
        $this->actingAs($this->restaurant())
            ->post(route('restaurant.tables.store'), ['title' => 'Mesa 1', 'customer_token' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(RestaurantTable::sole()->responsible_user_id);
        $this->assertDatabaseCount('restaurant_table_customers', 0);
    }

    public function test_staff_accounts_cannot_generate_a_customer_qr(): void
    {
        $restaurant = $this->restaurant();

        foreach ([$restaurant, $this->waiterOf($restaurant)] as $staff) {
            $this->actingAs($staff)->post(route('customer.link.create'))->assertRedirect(route('dashboard'));
            $this->actingAs($staff)->post(route('customer.link.cancel'))->assertRedirect(route('dashboard'));
        }

        $this->assertDatabaseCount('customer_link_tokens', 0);
    }

    public function test_generating_a_qr_requires_login(): void
    {
        $this->post(route('customer.link.create'))->assertRedirect(route('login'));
    }
}
