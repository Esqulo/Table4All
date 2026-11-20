<?php

namespace Tests\Feature\Restaurant;

use App\Models\QueueItem;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Customer self-ordering by table code, and the owner's sales dashboard. */
class CustomerAndDashboardTest extends TestCase
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

    // ── joining ──────────────────────────────────────────────────────────────

    public function test_public_table_page_shows_that_restaurants_menu(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $this->product($restaurant, ['name' => 'Chopp']);
        $this->product($this->restaurant(), ['name' => 'De outro bar']);
        $table = $this->openTable($restaurant);

        $this->get(route('table.show', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('table/show')
                ->where('restaurant.name', 'Bar do Zé')
                ->has('products', 1)
                ->where('products.0.name', 'Chopp')
            );
    }

    public function test_unknown_closed_or_deleted_table_codes_are_rejected(): void
    {
        $restaurant = $this->restaurant();
        $deleted = $this->openTable($restaurant);
        $deleted->delete();

        foreach (['000000', 'abc', $deleted->access_code] as $code) {
            $this->get(route('table.show', $code))->assertRedirect(route('table.join'));
            $this->actingAs($this->customer())->get(route('customer.table', $code))->assertRedirect(route('customer.join'));
        }
    }

    public function test_joined_tables_stay_in_the_customers_account_until_closed(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $customer = $this->customer();
        $first = $this->openTable($restaurant, ['title' => 'Mesa 1']);
        $second = $this->openTable($restaurant, ['title' => 'Mesa 2']);
        $elsewhere = $this->openTable($this->restaurant(), ['title' => 'Mesa 9']);

        // Joining one table never blocks joining others, at the same restaurant or not.
        foreach ([$first, $second, $elsewhere] as $table) {
            $this->actingAs($customer)->get(route('customer.table', $table->access_code))->assertOk();
        }

        $this->actingAs($customer)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/join')
                ->has('tables', 3)
                ->where('tables.2', ['code' => $first->access_code, 'title' => 'Mesa 1', 'restaurant' => 'Bar do Zé', 'status' => 'approved']));

        $first->forceFill(['closed_at' => now(), 'access_code' => null])->save();
        $elsewhere->delete();

        $this->actingAs($customer)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tables', 1)
                ->where('tables.0.code', $second->access_code));
    }

    public function test_joined_tables_are_listed_only_to_who_joined_them(): void
    {
        $table = $this->openTable($this->restaurant());
        $first = $this->customer();
        $second = $this->customer();

        $this->actingAs($first)->get(route('customer.table', $table->access_code))->assertOk();
        $this->actingAs($first)->get(route('customer.table', $table->access_code))->assertOk();
        $this->actingAs($second)->get(route('customer.table', $table->access_code))->assertOk();

        $this->assertDatabaseCount('restaurant_table_customers', 2);
        $this->actingAs($this->customer())->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page->has('tables', 0));
    }

    public function test_ordering_at_a_table_keeps_it_in_the_customers_account(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $customer = $this->customer();

        $this->actingAs($customer)->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $this->product($restaurant)->id, 'quantity' => 1]],
        ])->assertRedirect(route('customer.table', $table->access_code));

        $this->assertDatabaseHas('restaurant_table_customers', [
            'restaurant_table_id' => $table->id,
            'user_id' => $customer->id,
        ]);
    }

    // ── responsible and join approval ────────────────────────────────────────

    public function test_first_customer_becomes_responsible_and_others_must_be_approved(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant, ['title' => 'Mesa 7']);
        $owner = $this->customer();
        $guest = $this->customer();
        $this->queueItem($table, $this->product($restaurant));

        $this->actingAs($owner)->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/show')
                // Where the customer is, shown at the top of the screen.
                ->where('tableTitle', 'Mesa 7')
                ->where('restaurant', $restaurant->name)
                ->where('isResponsible', true)
                ->where('joinRequests', []));
        $this->assertSame($owner->id, $table->fresh()?->responsible_user_id);

        // The guest waits and sees nothing of the table's bill.
        $this->actingAs($guest)->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/waiting')
                ->where('status', 'pending')
                ->where('table', 'Mesa 7')
                ->missing('preparing')
                ->missing('total'));
        $this->actingAs($guest)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page->where('tables.0.status', 'pending'));

        $this->actingAs($owner)->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('joinRequests', [['id' => $guest->id, 'name' => $guest->name]]));

        $this->actingAs($owner)
            ->post(route('customer.join-requests.approve', [$table->access_code, $guest->id]))
            ->assertRedirect(route('customer.table', $table->access_code));

        $this->actingAs($guest)->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/show')
                ->where('isResponsible', false)
                ->has('preparing', 1));
        $this->assertSame($owner->id, $table->fresh()?->responsible_user_id);
    }

    public function test_customer_waiting_for_approval_cannot_order(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $guest = $this->customer();
        $this->actingAs($this->customer())->get(route('customer.table', $table->access_code))->assertOk();

        $this->actingAs($guest)->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $this->product($restaurant)->id, 'quantity' => 1]],
        ])->assertRedirect(route('customer.table', $table->access_code));

        $this->assertSame(0, QueueItem::where('restaurant_table_id', $table->id)->count());
        $this->assertDatabaseHas('restaurant_table_customers', [
            'restaurant_table_id' => $table->id,
            'user_id' => $guest->id,
            'status' => 'pending',
        ]);
    }

    public function test_rejected_customer_stays_out_and_the_table_leaves_their_list(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $owner = $this->customer();
        $guest = $this->customer();
        $this->actingAs($owner)->get(route('customer.table', $table->access_code))->assertOk();
        $this->actingAs($guest)->get(route('customer.table', $table->access_code))->assertOk();

        $this->actingAs($owner)->post(route('customer.join-requests.reject', [$table->access_code, $guest->id]));

        // Coming back doesn't ask again: the answer stands.
        $this->actingAs($guest)->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/waiting')
                ->where('status', 'rejected')
                ->where('attemptsLeft', 2));
        $this->actingAs($owner)->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page->where('joinRequests', []));
        $this->actingAs($guest)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page->has('tables', 0));
        $this->actingAs($guest)->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $this->product($restaurant)->id, 'quantity' => 1]],
        ]);
        $this->assertSame(0, QueueItem::where('restaurant_table_id', $table->id)->count());
    }

    public function test_only_the_responsible_answers_join_requests(): void
    {
        $table = $this->openTable($this->restaurant());
        $owner = $this->customer();
        $member = $this->customer();
        $guest = $this->customer();
        $this->actingAs($owner)->get(route('customer.table', $table->access_code))->assertOk();
        $this->actingAs($member)->get(route('customer.table', $table->access_code))->assertOk();
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$table->access_code, $member->id]));
        $this->actingAs($guest)->get(route('customer.table', $table->access_code))->assertOk();

        // Approved members and the guest themself can't let anyone in.
        foreach ([$member, $guest] as $someone) {
            $this->actingAs($someone)
                ->post(route('customer.join-requests.approve', [$table->access_code, $guest->id]))
                ->assertForbidden();
        }

        // The responsible of another table can't answer for this one.
        $otherOwner = $this->customer();
        $other = $this->openTable($this->restaurant());
        $this->actingAs($otherOwner)->get(route('customer.table', $other->access_code))->assertOk();
        $this->actingAs($otherOwner)
            ->post(route('customer.join-requests.approve', [$table->access_code, $guest->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('restaurant_table_customers', ['user_id' => $guest->id, 'status' => 'pending']);
    }

    public function test_join_requests_of_a_closed_table_cannot_be_answered(): void
    {
        $table = $this->openTable($this->restaurant());
        $code = $table->access_code;
        $owner = $this->customer();
        $guest = $this->customer();
        $this->actingAs($owner)->get(route('customer.table', $code))->assertOk();
        $this->actingAs($guest)->get(route('customer.table', $code))->assertOk();
        $table->forceFill(['closed_at' => now()])->save();

        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$code, $guest->id]))->assertNotFound();
    }

    public function test_a_table_opened_by_staff_gets_its_responsible_from_the_first_customer(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $waiter = $this->waiterOf($restaurant);
        $this->actingAs($waiter)->get(route('restaurant.tables.show', $table))->assertOk();

        $customer = $this->customer();
        $this->actingAs($customer)->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page->where('isResponsible', true));
    }

    public function test_restaurant_and_waiter_accounts_never_join_a_table_as_customers(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $product = $this->product($restaurant);

        foreach ([$restaurant, $this->waiterOf($restaurant), $this->restaurant()] as $staff) {
            $this->actingAs($staff)->get(route('customer.table', $table->access_code))
                ->assertRedirect(route('dashboard'));
            $this->actingAs($staff)->get(route('customer.join'))->assertRedirect(route('dashboard'));
            $this->actingAs($staff)->post(route('customer.order', $table->access_code), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertRedirect(route('dashboard'));
        }

        $this->assertNull($table->fresh()?->responsible_user_id);
        $this->assertDatabaseCount('restaurant_table_customers', 0);
        $this->assertSame(0, QueueItem::where('restaurant_table_id', $table->id)->count());
    }

    public function test_a_rejected_customer_can_ask_again_until_rejected_three_times(): void
    {
        $table = $this->openTable($this->restaurant());
        $code = $table->access_code;
        $owner = $this->customer();
        $guest = $this->customer();
        $this->actingAs($owner)->get(route('customer.table', $code))->assertOk();
        $this->actingAs($guest)->get(route('customer.table', $code))->assertOk();

        foreach ([2, 1, 0] as $attemptsLeft) {
            $this->actingAs($owner)->post(route('customer.join-requests.reject', [$code, $guest->id]));
            $this->actingAs($guest)->get(route('customer.table', $code))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('status', 'rejected')
                    ->where('attemptsLeft', $attemptsLeft));

            $this->actingAs($guest)->post(route('customer.join-requests.retry', $code))
                ->assertRedirect(route('customer.table', $code));
        }

        // The third rejection is final: asking again changes nothing, and neither does leaving.
        $this->actingAs($guest)->post(route('customer.leave', $code));
        $this->actingAs($guest)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', 'rejected')
                ->where('attemptsLeft', 0));
        $this->assertDatabaseHas('restaurant_table_customers', ['user_id' => $guest->id, 'rejections' => 3]);
    }

    public function test_asking_again_after_a_rejection_reaches_the_responsible(): void
    {
        $table = $this->openTable($this->restaurant());
        $code = $table->access_code;
        $owner = $this->customer();
        $guest = $this->customer();
        $this->actingAs($owner)->get(route('customer.table', $code))->assertOk();
        $this->actingAs($guest)->get(route('customer.table', $code))->assertOk();
        $this->actingAs($owner)->post(route('customer.join-requests.reject', [$code, $guest->id]));

        $this->actingAs($guest)->post(route('customer.join-requests.retry', $code));

        $this->actingAs($owner)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page->has('joinRequests', 1));
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$code, $guest->id]));
        $this->actingAs($guest)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page->component('customer/show'));
    }

    public function test_everyone_at_the_table_sees_who_is_there_in_the_order_they_joined(): void
    {
        $table = $this->openTable($this->restaurant());
        $code = $table->access_code;
        $owner = $this->customer();
        $first = $this->customer();
        $second = $this->customer();
        $waiting = $this->customer();
        foreach ([$owner, $first, $second, $waiting] as $customer) {
            $this->actingAs($customer)->get(route('customer.table', $code))->assertOk();
        }
        // Approval order doesn't matter: arrival order does.
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$code, $second->id]));
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$code, $first->id]));

        $this->actingAs($second)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('members', [
                    ['id' => $owner->id, 'name' => $owner->name, 'is_responsible' => true, 'is_me' => false],
                    ['id' => $first->id, 'name' => $first->name, 'is_responsible' => false, 'is_me' => false],
                    ['id' => $second->id, 'name' => $second->name, 'is_responsible' => false, 'is_me' => true],
                ])
                ->where('joinRequests', []));
    }

    public function test_when_the_responsible_leaves_the_next_to_have_joined_takes_over(): void
    {
        $table = $this->openTable($this->restaurant());
        $code = $table->access_code;
        $owner = $this->customer();
        $first = $this->customer();
        $second = $this->customer();
        foreach ([$owner, $first, $second] as $customer) {
            $this->actingAs($customer)->get(route('customer.table', $code))->assertOk();
        }
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$code, $second->id]));
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$code, $first->id]));

        $this->actingAs($owner)->post(route('customer.leave', $code))->assertRedirect(route('customer.join'));

        $this->assertSame($first->id, $table->fresh()?->responsible_user_id);
        $this->actingAs($owner)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page->has('tables', 0));

        // Coming back, the former responsible asks the new one like anybody else.
        $this->actingAs($owner)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page->component('customer/waiting'));
        $this->actingAs($first)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('isResponsible', true)
                ->where('joinRequests', [['id' => $owner->id, 'name' => $owner->name]]));

        $this->actingAs($first)->post(route('customer.leave', $code));
        $this->assertSame($second->id, $table->fresh()?->responsible_user_id);
    }

    public function test_when_the_last_customer_leaves_the_next_to_arrive_becomes_responsible(): void
    {
        $table = $this->openTable($this->restaurant());
        $code = $table->access_code;
        $owner = $this->customer();
        $waiting = $this->customer();
        $this->actingAs($owner)->get(route('customer.table', $code))->assertOk();
        $this->actingAs($waiting)->get(route('customer.table', $code))->assertOk();

        $this->actingAs($owner)->post(route('customer.leave', $code));
        $this->assertNull($table->fresh()?->responsible_user_id);

        // The waiting screen's next refresh lets them in as the new responsible.
        $this->actingAs($waiting)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/show')
                ->where('isResponsible', true));
    }

    public function test_a_customer_waiting_for_approval_can_cancel_the_request(): void
    {
        $table = $this->openTable($this->restaurant());
        $code = $table->access_code;
        $owner = $this->customer();
        $waiting = $this->customer();
        $this->actingAs($owner)->get(route('customer.table', $code))->assertOk();
        $this->actingAs($waiting)->get(route('customer.table', $code))->assertOk();

        $this->actingAs($waiting)->post(route('customer.leave', $code));

        $this->assertSame($owner->id, $table->fresh()?->responsible_user_id);
        $this->actingAs($owner)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page->where('joinRequests', []));
    }

    public function test_the_responsible_hands_the_table_over_to_someone_at_it(): void
    {
        $table = $this->openTable($this->restaurant());
        $code = $table->access_code;
        $owner = $this->customer();
        $member = $this->customer();
        $waiting = $this->customer();
        foreach ([$owner, $member, $waiting] as $customer) {
            $this->actingAs($customer)->get(route('customer.table', $code))->assertOk();
        }
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$code, $member->id]));

        // Only to someone already at the table, and only by the responsible.
        $this->actingAs($owner)->post(route('customer.responsible.transfer', [$code, $waiting->id]))->assertNotFound();
        $this->actingAs($owner)->post(route('customer.responsible.transfer', [$code, $this->customer()->id]))->assertNotFound();
        $this->actingAs($member)->post(route('customer.responsible.transfer', [$code, $member->id]))->assertForbidden();
        $this->assertSame($owner->id, $table->fresh()?->responsible_user_id);

        $this->actingAs($owner)->post(route('customer.responsible.transfer', [$code, $member->id]))
            ->assertRedirect(route('customer.table', $code));

        $this->assertSame($member->id, $table->fresh()?->responsible_user_id);
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$code, $waiting->id]))->assertForbidden();
        $this->actingAs($member)->post(route('customer.join-requests.approve', [$code, $waiting->id]));
        $this->actingAs($waiting)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page->component('customer/show'));
        // The former responsible stays at the table as a regular member.
        $this->actingAs($owner)->get(route('customer.table', $code))
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/show')
                ->where('isResponsible', false));
    }

    public function test_customer_routes_require_login(): void
    {
        $table = $this->openTable($this->restaurant());

        $this->get(route('customer.table', $table->access_code))->assertRedirect(route('login'));
        $this->post(route('customer.order', $table->access_code), [])->assertRedirect(route('login'));
    }

    public function test_customer_sees_balance_never_below_zero(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $this->billLine($table, $this->product($restaurant), 2, 10);
        $table->payments()->create(['method' => 'cash', 'amount' => 50]);

        $this->actingAs($this->customer())->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 20)
                ->where('paid', 50)
                ->where('remaining', 0)
            );
    }

    public function test_customer_menu_shows_promotion_prices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 20:30:00', 'UTC')); // Friday 17:30 in Brasilia
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant, ['price' => 10]);
        Sale::create([
            'user_id' => $restaurant->id, 'product_id' => $product->id, 'type' => 'periodic',
            'sale_price' => 6, 'days' => [5], 'start_time' => '17:00', 'end_time' => '20:00',
        ]);
        $table = $this->openTable($restaurant);

        $this->actingAs($this->customer())->get(route('customer.table', $table->access_code))
            ->assertInertia(fn (Assert $page) => $page->where('menu.0.price', 6));
    }

    public function test_customer_sees_progress_of_an_item_whose_product_was_deleted(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant, ['name' => 'Burger']);
        $table = $this->openTable($restaurant);
        $this->queueItem($table, $product);
        $product->delete();

        $this->actingAs($this->customer())->get(route('customer.table', $table->access_code))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('preparing.0.name', 'Burger'));
    }

    // ── ordering ─────────────────────────────────────────────────────────────

    public function test_kitchen_items_go_to_their_queue_and_counter_items_to_none(): void
    {
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchenQueue($restaurant);
        $burger = $this->product($restaurant, ['queue_id' => $kitchen->id]);
        $beer = $this->product($restaurant);
        $table = $this->openTable($restaurant);
        $customer = $this->customer();

        $this->actingAs($customer)->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $burger->id, 'quantity' => 1], ['product_id' => $beer->id, 'quantity' => 3]],
        ])->assertRedirect(route('customer.table', $table->access_code));

        $this->assertSame($kitchen->id, QueueItem::where('product_id', $burger->id)->sole()->queue_id);
        $this->assertNull(QueueItem::where('product_id', $beer->id)->sole()->queue_id);
        $this->assertSame(2, QueueItem::where('ordered_by_user_id', $customer->id)->where('status', 'pending')->count());
    }

    public function test_order_skips_products_of_other_restaurants(): void
    {
        $restaurant = $this->restaurant();
        $own = $this->product($restaurant);
        $foreign = $this->product($this->restaurant());
        $table = $this->openTable($restaurant);

        $this->actingAs($this->customer())->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $own->id, 'quantity' => 1], ['product_id' => $foreign->id, 'quantity' => 1]],
        ]);

        $this->assertSame([$own->id], QueueItem::pluck('product_id')->all());
    }

    public function test_order_with_nothing_orderable_is_reported_not_confirmed(): void
    {
        $restaurant = $this->restaurant();
        $gone = $this->product($restaurant);
        $gone->delete();
        $table = $this->openTable($restaurant);

        $this->actingAs($this->customer())->post(route('customer.order', $table->access_code), [
            'items' => [['product_id' => $gone->id, 'quantity' => 1]],
        ])->assertRedirect(route('customer.table', $table->access_code))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertSame(0, QueueItem::count());
    }

    public function test_order_quantities_are_bounded(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);
        $table = $this->openTable($restaurant);
        $customer = $this->customer();

        foreach ([
            [],
            ['items' => []],
            ['items' => [['product_id' => $product->id, 'quantity' => 0]]],
            ['items' => [['product_id' => $product->id, 'quantity' => 100]]],
            ['items' => [['product_id' => $product->id]]],
            ['items' => array_fill(0, 101, ['product_id' => $product->id, 'quantity' => 1])],
        ] as $payload) {
            $this->actingAs($customer)->post(route('customer.order', $table->access_code), $payload)->assertSessionHasErrors();
        }

        $this->assertSame(0, QueueItem::count());
    }

    public function test_order_on_a_closed_table_is_not_found(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->openTable($restaurant);
        $code = $table->access_code;
        $table->forceFill(['closed_at' => now()])->save(); // code not yet cleared

        $this->actingAs($this->customer())->post(route('customer.order', $code), [
            'items' => [['product_id' => $this->product($restaurant)->id, 'quantity' => 1]],
        ])->assertNotFound();
    }

    // ── dashboard ────────────────────────────────────────────────────────────

    public function test_dashboard_counts_only_this_restaurants_closed_tables_in_the_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 15:00:00', 'UTC'));
        $restaurant = $this->restaurant();
        $beer = $this->product($restaurant, ['name' => 'Chopp', 'price' => 99]); // current price must not matter

        $counted = $this->closedTable($restaurant);
        $this->billLine($counted, $beer, 3, 10);
        $counted->payments()->create(['method' => 'pix', 'amount' => 30]);

        $this->billLine($this->openTable($restaurant), $beer, 5, 10);                       // still open
        $this->billLine($deleted = $this->closedTable($restaurant), $beer, 7, 10);
        $deleted->delete();                                                                  // deleted
        $old = $this->closedTable($restaurant);
        $old->forceFill(['closed_at' => now()->subDays(40)])->save();
        $this->billLine($old, $beer, 9, 10);                                                 // outside 30 days
        $other = $this->restaurant();
        $this->billLine($this->closedTable($other), $this->product($other), 11, 10);        // someone else

        $this->actingAs($restaurant)->get(route('restaurant.dashboards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', '30')
                ->where('summary.total_revenue', 30)
                ->where('summary.tables_closed', 1)
                ->where('summary.items_sold', 3)
                ->where('summary.avg_table_revenue', 30)
                ->where('top_products.0.name', 'Chopp')
                ->has('recent_tables', 1)
                ->where('payment_methods.0.method', 'pix')
            );
    }

    public function test_dashboard_with_no_sales_shows_zeros(): void
    {
        $this->actingAs($this->restaurant())->get(route('restaurant.dashboards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_revenue', 0)
                ->where('summary.tables_closed', 0)
                ->where('summary.avg_table_revenue', 0)
                ->has('timeline', 30)
                ->where('timeline.0.revenue', 0)
            );
    }

    public function test_dashboard_period_falls_back_to_thirty_days(): void
    {
        $restaurant = $this->restaurant();

        foreach (['365', 'abc', '-7'] as $period) {
            $this->actingAs($restaurant)->get(route('restaurant.dashboards.index', ['period' => $period]))
                ->assertInertia(fn (Assert $page) => $page->where('filters.period', '30'));
        }

        $this->actingAs($restaurant)->get(route('restaurant.dashboards.index', ['period' => 7]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.period', '7'));
    }

    public function test_late_night_sales_count_on_the_local_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00', 'UTC'));
        $restaurant = $this->restaurant();
        $table = $this->closedTable($restaurant);
        // 01:30 UTC on the 21st is 22:30 on the 20th in Brasilia.
        $table->forceFill(['closed_at' => Carbon::parse('2026-11-21 01:30:00', 'UTC')])->save();
        $this->billLine($table, $this->product($restaurant), 1, 10);

        $this->actingAs($restaurant)->get(route('restaurant.dashboards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.28.bucket', '2026-11-20')
                ->where('timeline.28.revenue', 10)
                ->where('timeline.29.revenue', 0)
            );
    }

    public function test_dashboard_reports_orders_customers_left_without(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 15:00:00', 'UTC'));
        $restaurant = $this->restaurant();
        $burger = $this->product($restaurant, ['price' => 30]);

        $left = $this->closedTable($restaurant);
        $this->queueItem($left, $burger, ['status' => 'abandoned', 'quantity' => 2, 'price' => 25]);
        $this->queueItem($left, $burger, ['status' => 'delivered', 'quantity' => 5]);
        $old = $this->closedTable($restaurant);
        $old->forceFill(['closed_at' => now()->subDays(40)])->save();
        $this->queueItem($old, $burger, ['status' => 'abandoned']);
        $other = $this->restaurant();
        $this->queueItem($this->closedTable($other), $this->product($other), ['status' => 'abandoned']);

        $this->actingAs($restaurant)->get(route('restaurant.dashboards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.abandoned_items', 2)
                ->where('summary.abandoned_value', 50)
                ->where('summary.total_revenue', 0)
            );
    }
}
