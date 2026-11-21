<?php

namespace Tests\Feature\Restaurant;

use App\Enums\QueueItemStatus;
use App\Enums\StaffFeature;
use App\Enums\TableCustomerStatus;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use App\Models\TableCall;
use App\Models\User;
use App\Support\TableChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Moving items to another table, and merging two tables, with both tables locked together. */
class TableTransferTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $restaurant;

    private RestaurantTable $from;

    private RestaurantTable $to;

    private Product $beer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->restaurant = $this->restaurant();
        $this->from = $this->openTable($this->restaurant, ['title' => 'Mesa 1']);
        $this->to = $this->openTable($this->restaurant, ['title' => 'Mesa 2']);
        $this->beer = $this->product($this->restaurant, ['name' => 'Chopp', 'price' => 10]);
    }

    /** @param  array<string, mixed>  $data */
    private function transfer(array $data, ?User $as = null, ?RestaurantTable $from = null): TestResponse
    {
        return $this->actingAs($as ?? $this->restaurant)
            ->post(route('restaurant.tables.transfer', $from ?? $this->from), ['to_table_id' => $this->to->id, ...$data]);
    }

    private function merge(?User $as = null, array $data = []): TestResponse
    {
        return $this->actingAs($as ?? $this->restaurant)
            ->post(route('restaurant.tables.merge', $this->from), ['into_table_id' => $this->to->id, ...$data]);
    }

    /** @return array<string, int> "customer id or -" => units of the beer on the table */
    private function beerOn(RestaurantTable $table): array
    {
        return DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->where('product_id', $this->beer->id)
            ->orderBy('ordered_by_user_id')
            ->get(['ordered_by_user_id', 'quantity'])
            ->mapWithKeys(fn ($r) => [(string) ($r->ordered_by_user_id ?? '-') => (int) $r->quantity])
            ->all();
    }

    private function staff(array $permissions): User
    {
        $member = $this->waiterOf($this->restaurant);
        $member->forceFill(['staff_permissions' => $permissions])->save();

        return $member->fresh() ?? $member;
    }

    // ── moving items ─────────────────────────────────────────────────────────

    public function test_units_move_unattributed_first_and_keep_who_ordered_them(): void
    {
        $customer = $this->customer();
        $this->billLine($this->from, $this->beer, 2, 10.00);
        $this->billLine($this->from, $this->beer, 1, 10.00, $customer->id);

        $this->transfer(['lines' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 2]]])
            ->assertRedirect(route('restaurant.tables.show', $this->from))
            ->assertInertiaFlash('toast.message', 'tables.msg_items_transferred');

        $this->assertSame([(string) $customer->id => 1], $this->beerOn($this->from));
        $this->assertSame(['-' => 2], $this->beerOn($this->to));

        $this->transfer(['lines' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 1]]]);

        $this->assertSame([], $this->beerOn($this->from));
        $this->assertSame([(string) $customer->id => 1, '-' => 2], $this->beerOn($this->to));
    }

    public function test_units_join_the_same_line_on_the_other_table(): void
    {
        $this->billLine($this->from, $this->beer, 3, 10.00);
        $this->billLine($this->to, $this->beer, 1, 10.00);

        $this->transfer(['lines' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 3]]]);

        $this->assertSame(['-' => 4], $this->beerOn($this->to));
        $this->assertSame(1, DB::table('restaurant_table_product')->where('restaurant_table_id', $this->to->id)->count());
    }

    public function test_items_being_prepared_move_whole(): void
    {
        $burger = $this->product($this->restaurant, ['name' => 'Hambúrguer', 'queue_id' => $this->kitchenQueue($this->restaurant)->id]);
        $pending = $this->queueItem($this->from, $burger, ['notes' => 'sem cebola']);
        $done = $this->queueItem($this->from, $burger, ['status' => QueueItemStatus::DONE->value]);

        $this->transfer(['queue_item_ids' => [$pending->id, $done->id]]);

        $this->assertSame([$this->to->id, $this->to->id], QueueItem::orderBy('id')->pluck('restaurant_table_id')->all());
        $this->assertSame('sem cebola', $pending->fresh()?->notes);
    }

    public function test_nothing_moves_when_part_of_it_is_gone(): void
    {
        $burger = $this->product($this->restaurant, ['name' => 'Hambúrguer', 'queue_id' => $this->kitchenQueue($this->restaurant)->id]);
        $delivered = $this->queueItem($this->from, $burger, ['status' => QueueItemStatus::DELIVERED->value]);
        $this->billLine($this->from, $this->beer, 2, 10.00);

        // More units than the table has.
        $this->transfer(['lines' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 3]]])
            ->assertInertiaFlash('toast.message', 'tables.error_transfer_stale');
        // An item already delivered, together with units that are there.
        $this->transfer([
            'lines' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 1]],
            'queue_item_ids' => [$delivered->id],
        ])->assertInertiaFlash('toast.message', 'tables.error_transfer_stale');

        $this->assertSame(['-' => 2], $this->beerOn($this->from));
        $this->assertSame([], $this->beerOn($this->to));
        $this->assertSame($this->from->id, $delivered->fresh()?->restaurant_table_id);
    }

    public function test_the_same_line_sent_twice_in_a_request_counts_once_against_the_table(): void
    {
        $this->billLine($this->from, $this->beer, 2, 10.00);

        $this->transfer(['lines' => [
            ['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 2],
            ['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 2],
        ]])->assertInertiaFlash('toast.message', 'tables.error_transfer_stale');

        $this->assertSame(['-' => 2], $this->beerOn($this->from));
    }

    public function test_the_destination_must_be_another_open_table_of_the_restaurant(): void
    {
        $this->billLine($this->from, $this->beer, 1, 10.00);
        $line = ['lines' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 1]]];

        foreach ([
            $this->closedTable($this->restaurant)->id,
            $this->openTable($this->restaurant())->id,
            $this->from->id,
            999999,
        ] as $target) {
            $this->transfer([...$line, 'to_table_id' => $target])->assertSessionHasErrors('to_table_id');
        }

        $this->transfer(['queue_item_ids' => [], 'lines' => []])->assertSessionHasErrors(['lines', 'queue_item_ids']);
        $this->assertSame(['-' => 1], $this->beerOn($this->from));
    }

    public function test_another_restaurant_cant_move_items_off_a_table(): void
    {
        $this->billLine($this->from, $this->beer, 1, 10.00);

        $this->transfer(['lines' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 1]]], $this->restaurant())
            ->assertForbidden();

        $this->assertSame(['-' => 1], $this->beerOn($this->from));
    }

    public function test_managing_tables_is_what_allows_it(): void
    {
        $this->billLine($this->from, $this->beer, 2, 10.00);
        $line = ['lines' => [['product_id' => $this->beer->id, 'price' => 10, 'quantity' => 1]]];

        $this->transfer($line, $this->staff([StaffFeature::TABLES->value => 'read']))
            ->assertInertiaFlash('toast.message', 'staff.error_no_permission');
        $this->merge($this->staff([StaffFeature::TABLES->value => 'read']));
        $this->assertSame(['-' => 2], $this->beerOn($this->from));

        $this->transfer($line, $this->staff([StaffFeature::TABLES->value => 'write']));
        $this->assertSame(['-' => 1], $this->beerOn($this->from));
    }

    // ── merging ──────────────────────────────────────────────────────────────

    public function test_merging_brings_everything_over_and_removes_the_table(): void
    {
        $alice = $this->customer();
        $bob = $this->customer();
        $this->from->forceFill(['responsible_user_id' => $alice->id])->save();
        $this->to->forceFill(['responsible_user_id' => $bob->id])->save();
        DB::table('restaurant_table_customers')->insert([
            ['restaurant_table_id' => $this->from->id, 'user_id' => $alice->id, 'status' => TableCustomerStatus::APPROVED->value, 'rejections' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['restaurant_table_id' => $this->to->id, 'user_id' => $bob->id, 'status' => TableCustomerStatus::APPROVED->value, 'rejections' => 0, 'created_at' => now(), 'updated_at' => now()],
            // Alice had asked to join Mesa 2 too: being let in at Mesa 1 lets her in.
            ['restaurant_table_id' => $this->to->id, 'user_id' => $alice->id, 'status' => TableCustomerStatus::PENDING->value, 'rejections' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->billLine($this->from, $this->beer, 2, 10.00, $alice->id);
        $this->billLine($this->from, $this->beer, 1, 10.00);
        $this->billLine($this->to, $this->beer, 1, 10.00);
        $waiter = $this->waiterOf($this->restaurant);
        $this->from->forceFill(['waiter_id' => $this->waiterOf($this->restaurant)->id])->save();
        $this->to->forceFill(['waiter_id' => $waiter->id])->save();
        $this->from->payments()->create(['method' => 'cash', 'amount' => 15, 'registered_by_id' => $this->restaurant->id, 'registered_by_type' => 'restaurant']);
        $item = $this->queueItem($this->from, $this->beer);
        TableChat::send($this->from->id, $alice->id, 'Oi!');
        TableChat::send($this->to->id, $bob->id, 'Olá!');
        TableChat::react($this->to->id, $bob->id, TableChat::REACTIONS[0]);
        TableCall::open($this->from, TableCall::TYPES[0], $alice->id);

        $this->merge()
            ->assertRedirect(route('restaurant.tables.show', $this->to))
            ->assertInertiaFlash('toast.message', 'tables.msg_tables_merged');

        $this->assertSame([(string) $alice->id => 2, '-' => 2], $this->beerOn($this->to));
        $this->assertSame(15.0, (float) $this->to->payments()->sum('amount'));
        $this->assertSame($this->to->id, $item->fresh()?->restaurant_table_id);
        // Both conversations start over at the merged table.
        $this->assertSame(0, DB::table('table_messages')->whereIn('restaurant_table_id', [$this->from->id, $this->to->id])->count());
        $this->assertSame(0, DB::table('table_reactions')->whereIn('restaurant_table_id', [$this->from->id, $this->to->id])->count());
        // Mesa 2 keeps its own waiter.
        $this->assertSame($waiter->id, $this->to->fresh()?->waiter_id);
        $this->assertSame(
            [$alice->id => 'approved', $bob->id => 'approved'],
            DB::table('restaurant_table_customers')->where('restaurant_table_id', $this->to->id)->orderBy('user_id')->pluck('status', 'user_id')->all(),
        );
        // Mesa 2 keeps its own responsible.
        $this->assertSame($bob->id, $this->to->fresh()?->responsible_user_id);

        $gone = RestaurantTable::withTrashed()->find($this->from->id);
        $this->assertNotNull($gone?->deleted_at);
        $this->assertNull($gone->access_code);
        $this->assertSame(0, TableCall::where('restaurant_table_id', $this->from->id)->whereNull('resolved_at')->count());
    }

    public function test_the_merged_table_takes_the_responsible_when_it_had_none(): void
    {
        $alice = $this->customer();
        $this->from->forceFill(['responsible_user_id' => $alice->id])->save();

        $this->merge();

        $this->assertSame($alice->id, $this->to->fresh()?->responsible_user_id);
    }

    public function test_a_resent_merge_just_follows_to_the_merged_table(): void
    {
        $this->billLine($this->from, $this->beer, 1, 10.00);

        $this->merge(data: ['request_key' => 'merge-1']);
        $this->merge(data: ['request_key' => 'merge-1'])->assertRedirect(route('restaurant.tables.show', $this->to));
        // Even with a new key (the screen reloaded): the table is already gone.
        $this->merge(data: ['request_key' => 'merge-2'])->assertRedirect(route('restaurant.tables.show', $this->to));

        $this->assertSame(['-' => 1], $this->beerOn($this->to));
    }

    public function test_merging_waits_for_an_online_payment_in_progress(): void
    {
        $this->billLine($this->from, $this->beer, 1, 10.00);
        DB::table('online_payments')->insert([
            'restaurant_table_id' => $this->from->id, 'restaurant_id' => $this->restaurant->id,
            'stripe_account_id' => 'acct_x', 'status' => 'pending', 'stripe_key' => (string) Str::uuid(),
            'amount_cents' => 1000, 'charge_cents' => 1000, 'fee_tier' => 'default',
            'fee_rate_bps' => 450, 'fee_cents' => 45, 'application_fee_cents' => 45,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->merge()->assertInertiaFlash('toast.message', 'tables.error_merge_payment');

        $this->assertNull(RestaurantTable::find($this->from->id)?->deleted_at);
        $this->assertSame(['-' => 1], $this->beerOn($this->from));
    }

    public function test_a_merged_bill_closes_with_both_tables_paid(): void
    {
        $this->billLine($this->from, $this->beer, 2, 10.00);
        $this->billLine($this->to, $this->beer, 1, 10.00);
        $this->from->payments()->create(['method' => 'cash', 'amount' => 20, 'registered_by_id' => $this->restaurant->id, 'registered_by_type' => 'restaurant']);
        $this->merge();

        $this->actingAs($this->restaurant)->patch(route('restaurant.tables.close', $this->to));
        $this->assertNull($this->to->fresh()?->closed_at);

        $this->actingAs($this->restaurant)->post(route('restaurant.tables.add-payment', $this->to), ['method' => 'cash', 'amount' => 10]);
        $this->actingAs($this->restaurant)->patch(route('restaurant.tables.close', $this->to));
        $this->assertNotNull($this->to->fresh()?->closed_at);
    }
}
