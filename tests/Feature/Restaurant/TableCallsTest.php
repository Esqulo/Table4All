<?php

namespace Tests\Feature\Restaurant;

use App\Enums\StaffRole;
use App\Models\RestaurantTable;
use App\Models\TableCall;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** "Chamar o garçom" / "Pedir a conta" at the table, and the staff's calls board. */
class TableCallsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $restaurant;

    private RestaurantTable $table;

    /** The first customer at the table (its responsible). */
    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->restaurant = $this->restaurant();
        $this->table = $this->openTable($this->restaurant, ['title' => 'Mesa 7']);
        $this->guest = $this->seat($this->table);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A customer who opened the table's page first: its responsible, already in. */
    private function seat(RestaurantTable $table): User
    {
        $customer = $this->customer();
        $this->actingAs($customer)->get(route('customer.table', $table->access_code))->assertOk();

        return $customer;
    }

    private function ring(User $customer, string $type, ?RestaurantTable $table = null): TestResponse
    {
        return $this->actingAs($customer)->post(
            route('customer.calls.store', ($table ?? $this->table)->access_code),
            ['type' => $type],
        );
    }

    /** @return list<array<string, mixed>> */
    private function board(User $viewer): array
    {
        return $this->actingAs($viewer)->get(route('restaurant.calls.index'))
            ->assertOk()
            ->viewData('page')['props']['calls'];
    }

    // ── the table ────────────────────────────────────────────────────────────

    public function test_a_customer_calls_the_waiter_and_asks_for_the_bill(): void
    {
        $this->ring($this->guest, 'waiter')->assertRedirect(route('customer.table', $this->table->access_code));
        $this->ring($this->guest, 'bill');

        $this->assertSame(2, TableCall::whereNull('resolved_at')->count());
        $this->actingAs($this->guest)->get(route('customer.table', $this->table->access_code))
            ->assertInertia(fn (Assert $page) => $page
                ->has('calls.waiter')
                ->has('calls.bill'));
        $call = TableCall::where('type', 'waiter')->firstOrFail();
        $this->assertSame($this->restaurant->id, $call->user_id);
        $this->assertSame($this->guest->id, $call->requested_by_user_id);
    }

    public function test_calling_again_while_waiting_does_not_queue_a_second_call(): void
    {
        $other = $this->customer();
        $this->actingAs($other)->get(route('customer.table', $this->table->access_code));
        $this->actingAs($this->guest)->post(route('customer.join-requests.approve', [$this->table->access_code, $other->id]));

        $this->ring($this->guest, 'waiter');
        $this->ring($this->guest, 'waiter');
        $this->ring($other, 'waiter');

        $this->assertSame(1, TableCall::count());
    }

    public function test_after_an_answer_the_table_can_call_again(): void
    {
        $this->ring($this->guest, 'waiter');
        $first = TableCall::firstOrFail();
        $this->actingAs($this->restaurant)->patch(route('restaurant.calls.answer', $first));

        $this->ring($this->guest, 'waiter');

        $this->assertSame(2, TableCall::count());
        $this->assertSame(1, TableCall::whereNull('resolved_at')->count());
    }

    public function test_the_table_can_take_its_call_back(): void
    {
        $this->ring($this->guest, 'waiter');
        $this->ring($this->guest, 'bill');

        $this->actingAs($this->guest)->delete(route('customer.calls.cancel', [$this->table->access_code, 'waiter']))
            ->assertRedirect(route('customer.table', $this->table->access_code));

        $this->assertSame(TableCall::CANCELLED, TableCall::where('type', 'waiter')->value('resolution'));
        $this->assertNull(TableCall::where('type', 'bill')->value('resolved_at'));
        $this->assertSame(['bill'], array_column($this->board($this->restaurant), 'type'));
    }

    public function test_only_people_at_the_table_can_call(): void
    {
        $stranger = $this->customer();

        $this->ring($stranger, 'waiter')->assertForbidden();
        $this->actingAs($stranger)->delete(route('customer.calls.cancel', [$this->table->access_code, 'waiter']))->assertForbidden();
        // Staff don't call their own waiters.
        $this->ring($this->waiterOf($this->restaurant), 'waiter')->assertRedirect(route('dashboard'));
        $this->ring($this->guest, 'kitchen')->assertSessionHasErrors('type');

        $this->assertSame(0, TableCall::count());
    }

    public function test_a_closed_table_cannot_call(): void
    {
        $code = $this->table->access_code;
        $this->table->forceFill(['closed_at' => now()])->save();

        $this->actingAs($this->guest)->post(route('customer.calls.store', $code), ['type' => 'waiter'])->assertNotFound();

        $this->assertSame(0, TableCall::count());
    }

    // ── the board ────────────────────────────────────────────────────────────

    public function test_the_board_lists_the_restaurants_open_calls_oldest_first(): void
    {
        $product = $this->product($this->restaurant);
        $this->billLine($this->table, $product, 3, 10.0);
        $this->table->payments()->create(['method' => 'cash', 'amount' => 12]);

        Carbon::setTestNow(now()->subMinutes(6));
        $this->ring($this->guest, 'bill');
        Carbon::setTestNow();

        $second = $this->openTable($this->restaurant, ['title' => 'Mesa 2']);
        $this->ring($this->seat($second), 'waiter', $second);

        // Another restaurant's call stays on its own board.
        $foreignTable = $this->openTable($this->restaurant());
        $this->ring($this->seat($foreignTable), 'waiter', $foreignTable);

        $calls = $this->board($this->waiterOf($this->restaurant));

        $this->assertSame(['Mesa 7', 'Mesa 2'], array_column(array_column($calls, 'table'), 'title'));
        $this->assertSame('bill', $calls[0]['type']);
        $this->assertSame(30.0, $calls[0]['total']);
        $this->assertSame(18.0, $calls[0]['remaining']);
        $this->assertSame($this->guest->name, $calls[0]['requested_by']);
    }

    public function test_answering_takes_the_call_off_the_board(): void
    {
        $this->ring($this->guest, 'waiter');
        $call = TableCall::firstOrFail();
        $waiter = $this->waiterOf($this->restaurant);

        $this->actingAs($waiter)->from(route('restaurant.calls.index'))
            ->patch(route('restaurant.calls.answer', $call))
            ->assertRedirect(route('restaurant.calls.index'));

        $this->assertSame([], $this->board($waiter));
        $call->refresh();
        $this->assertSame(TableCall::ANSWERED, $call->resolution);
        $this->assertSame($waiter->id, $call->resolved_by_user_id);
        // The table sees the waiter is on the way: the button is back.
        $this->actingAs($this->guest)->get(route('customer.table', $this->table->access_code))
            ->assertInertia(fn (Assert $page) => $page->where('calls', []));
    }

    public function test_answering_twice_keeps_the_first_answer(): void
    {
        $this->ring($this->guest, 'waiter');
        $call = TableCall::firstOrFail();
        $first = $this->waiterOf($this->restaurant);
        $second = $this->waiterOf($this->restaurant);

        $this->actingAs($first)->patch(route('restaurant.calls.answer', $call));
        $this->actingAs($second)->patch(route('restaurant.calls.answer', $call));

        $this->assertSame($first->id, $call->fresh()->resolved_by_user_id);
    }

    public function test_closing_or_deleting_the_table_clears_its_calls(): void
    {
        $this->ring($this->guest, 'waiter');
        $product = $this->product($this->restaurant);
        $this->billLine($this->table, $product, 1, 10.0);
        $this->table->payments()->create(['method' => 'cash', 'amount' => 10]);

        $this->actingAs($this->restaurant)->patch(route('restaurant.tables.close', $this->table))->assertSessionHasNoErrors();

        $this->assertSame(TableCall::CLOSED, TableCall::firstOrFail()->resolution);
        $this->assertSame([], $this->board($this->restaurant));

        $empty = $this->openTable($this->restaurant);
        $this->ring($this->seat($empty), 'bill', $empty);
        $this->actingAs($this->restaurant)->delete(route('restaurant.tables.destroy', $empty));

        $this->assertSame(0, TableCall::whereNull('resolved_at')->count());
    }

    public function test_board_access_follows_the_staff_permissions(): void
    {
        $this->ring($this->guest, 'waiter');
        $call = TableCall::firstOrFail();

        $reader = $this->waiterOf($this->restaurant);
        $reader->forceFill(['staff_role' => StaffRole::WAITER, 'staff_permissions' => ['calls' => 'read']])->save();
        $this->assertCount(1, $this->board($reader));
        $this->actingAs($reader)->patch(route('restaurant.calls.answer', $call));
        $this->assertNull($call->fresh()->resolved_at);

        $outsider = $this->waiterOf($this->restaurant);
        $outsider->forceFill(['staff_permissions' => ['tables' => 'write']])->save();
        $this->actingAs($outsider)->get(route('restaurant.calls.index'))->assertRedirect(route('dashboard'));

        $this->actingAs($this->guest)->get(route('restaurant.calls.index'))->assertRedirect(route('dashboard'));
    }

    public function test_another_restaurant_cannot_answer_the_call(): void
    {
        $this->ring($this->guest, 'waiter');
        $call = TableCall::firstOrFail();

        $this->actingAs($this->restaurant())->patch(route('restaurant.calls.answer', $call))->assertForbidden();

        $this->assertNull($call->fresh()->resolved_at);
    }

    public function test_the_sidebar_counts_the_waiting_calls(): void
    {
        $this->ring($this->guest, 'waiter');
        $this->ring($this->guest, 'bill');

        $this->actingAs($this->waiterOf($this->restaurant))->get(route('restaurant.tables.index'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.openCalls', 2));
        $this->actingAs($this->guest)->get(route('customer.join'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.openCalls', null));
    }

    public function test_the_board_refresh_asks_only_for_the_calls(): void
    {
        $this->ring($this->guest, 'waiter');

        $this->actingAs($this->restaurant)->get(route('restaurant.calls.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly(['calls'], fn (Assert $reload) => $reload->has('calls', 1)));
    }

    public function test_board_queries_do_not_grow_with_the_calls(): void
    {
        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->actingAs($this->restaurant)->get(route('restaurant.calls.index'))->assertOk();

            return $queries;
        };

        $this->ring($this->guest, 'waiter');
        $one = $count();

        foreach (range(1, 8) as $i) {
            $table = $this->openTable($this->restaurant);
            $this->ring($this->seat($table), 'bill', $table);
        }

        $this->assertSame($one, $count());
    }
}
