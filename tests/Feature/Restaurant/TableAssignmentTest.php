<?php

namespace Tests\Feature\Restaurant;

use App\Enums\StaffRole;
use App\Models\RestaurantTable;
use App\Models\TableCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** The waiter in charge of each table: opening, taking, leaving and assigning, and the setting. */
class TableAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->restaurant = $this->restaurant();
    }

    /** @param  array<string, string>  $permissions */
    private function staff(array $permissions = ['tables' => 'write'], StaffRole $role = StaffRole::WAITER, ?User $restaurant = null): User
    {
        $member = $this->waiterOf($restaurant ?? $this->restaurant);
        $member->forceFill(['staff_role' => $role, 'staff_permissions' => $permissions])->save();

        return $member->fresh();
    }

    private function waiterOfTable(RestaurantTable $table): ?int
    {
        return RestaurantTable::whereKey($table->id)->value('waiter_id');
    }

    private function turn(bool $on): void
    {
        $this->actingAs($this->restaurant)
            ->put(route('restaurant.establishment.service.update'), ['table_assignment_enabled' => $on])
            ->assertSessionHasNoErrors();
    }

    // ── opening ──────────────────────────────────────────────────────────────

    public function test_the_waiter_who_opens_a_table_is_in_charge_of_it(): void
    {
        $waiter = $this->staff();

        $this->actingAs($waiter)->post(route('restaurant.tables.store'), ['title' => 'Mesa 1']);
        $this->actingAs($this->restaurant)->post(route('restaurant.tables.store'), ['title' => 'Mesa 2']);

        $this->assertSame($waiter->id, RestaurantTable::where('title', 'Mesa 1')->value('waiter_id'));
        // The owner isn't a waiter: their table starts free.
        $this->assertNull(RestaurantTable::where('title', 'Mesa 2')->value('waiter_id'));
    }

    public function test_with_the_feature_off_nobody_is_put_in_charge(): void
    {
        $this->turn(false);
        $waiter = $this->staff();

        $this->actingAs($waiter)->post(route('restaurant.tables.store'), ['title' => 'Mesa 1']);

        $this->assertNull(RestaurantTable::where('title', 'Mesa 1')->value('waiter_id'));
        $this->actingAs($waiter)->get(route('restaurant.tables.index'))
            ->assertInertia(fn (Assert $page) => $page->where('assignment.enabled', false));
    }

    // ── taking and leaving ───────────────────────────────────────────────────

    public function test_a_waiter_takes_a_free_table_and_can_leave_it(): void
    {
        $waiter = $this->staff();
        $table = $this->openTable($this->restaurant);

        $this->actingAs($waiter)->from(route('restaurant.tables.index'))
            ->patch(route('restaurant.tables.claim', $table))
            ->assertRedirect(route('restaurant.tables.index'))
            ->assertInertiaFlash('toast.message', 'tables.msg_claimed');
        $this->assertSame($waiter->id, $this->waiterOfTable($table));

        $this->patch(route('restaurant.tables.release', $table))->assertInertiaFlash('toast.message', 'tables.msg_released');
        $this->assertNull($this->waiterOfTable($table));
    }

    public function test_a_table_already_taken_cannot_be_taken_again(): void
    {
        $first = $this->staff();
        $second = $this->staff();
        $table = $this->openTable($this->restaurant);

        $this->actingAs($first)->patch(route('restaurant.tables.claim', $table));
        $this->actingAs($second)->patch(route('restaurant.tables.claim', $table))
            ->assertInertiaFlash('toast.message', 'tables.error_already_taken');
        // Nor can someone else free it on the waiter's behalf.
        $this->actingAs($second)->patch(route('restaurant.tables.release', $table));

        $this->assertSame($first->id, $this->waiterOfTable($table));
    }

    public function test_only_team_members_who_work_the_tables_take_them(): void
    {
        $table = $this->openTable($this->restaurant);

        // Read-only on tables: changes are refused.
        $this->actingAs($this->staff(['tables' => 'read']))->patch(route('restaurant.tables.claim', $table));
        // The owner assigns tables; taking one isn't for them.
        $this->actingAs($this->restaurant)->patch(route('restaurant.tables.claim', $table))->assertForbidden();
        // Another restaurant's waiter.
        $this->actingAs($this->staff(restaurant: $this->restaurant()))->patch(route('restaurant.tables.claim', $table))->assertForbidden();

        $this->assertNull($this->waiterOfTable($table));
    }

    public function test_a_closed_table_is_not_taken(): void
    {
        $table = $this->closedTable($this->restaurant);

        $this->actingAs($this->staff())->patch(route('restaurant.tables.claim', $table))->assertStatus(422);

        $this->assertNull($this->waiterOfTable($table));
    }

    // ── assigning ────────────────────────────────────────────────────────────

    public function test_the_owner_and_managers_assign_and_unassign_waiters(): void
    {
        $waiter = $this->staff();
        $other = $this->staff();
        $manager = $this->staff([], StaffRole::MANAGER);
        $table = $this->openTable($this->restaurant);

        $this->actingAs($this->restaurant)->put(route('restaurant.tables.assign', $table), ['waiter_id' => $waiter->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($waiter->id, $this->waiterOfTable($table));

        // A manager moves it to someone else, even with tables read-only for them.
        $this->actingAs($manager)->put(route('restaurant.tables.assign', $table), ['waiter_id' => $other->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($other->id, $this->waiterOfTable($table));

        $this->actingAs($manager)->put(route('restaurant.tables.assign', $table), ['waiter_id' => null])
            ->assertInertiaFlash('toast.message', 'tables.msg_unassigned');
        $this->assertNull($this->waiterOfTable($table));
    }

    public function test_waiters_do_not_assign_tables_to_others(): void
    {
        $waiter = $this->staff();
        $other = $this->staff();
        $table = $this->openTable($this->restaurant);

        $this->actingAs($waiter)->put(route('restaurant.tables.assign', $table), ['waiter_id' => $other->id]);

        $this->assertNull($this->waiterOfTable($table));
    }

    public function test_tables_go_only_to_team_members_who_work_them(): void
    {
        $table = $this->openTable($this->restaurant);
        $noTables = $this->staff(['delivery' => 'write']);

        foreach ([
            $this->customer()->id,
            $this->staff(restaurant: $this->restaurant())->id,
            $noTables->id,
            999999,
        ] as $candidate) {
            $this->actingAs($this->restaurant)
                ->put(route('restaurant.tables.assign', $table), ['waiter_id' => $candidate])
                ->assertSessionHasErrors('waiter_id');
        }

        $this->assertNull($this->waiterOfTable($table));
    }

    public function test_the_table_page_says_who_is_in_charge_and_what_the_viewer_can_do(): void
    {
        $waiter = $this->staff();
        $reader = $this->staff(['tables' => 'read']);
        $table = $this->openTable($this->restaurant);
        RestaurantTable::whereKey($table->id)->update(['waiter_id' => $waiter->id]);

        $this->actingAs($this->restaurant)->get(route('restaurant.tables.show', $table))
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignment.enabled', true)
                ->where('assignment.canAssign', true)
                ->where('assignment.canServe', false)
                ->where('assignment.waiter', ['id' => $waiter->id, 'name' => $waiter->name])
                // Everyone who can at least see the tables can be given one.
                ->where('assignment.candidates', fn ($c) => collect($c)->pluck('id')->sort()->values()->all() === collect([$waiter->id, $reader->id])->sort()->values()->all()));

        $this->actingAs($waiter)->get(route('restaurant.tables.show', $table))
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignment.canAssign', false)
                ->where('assignment.canServe', true)
                ->where('assignment.candidates', []));

        $this->actingAs($waiter)->get(route('restaurant.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.0.waiter.id', $waiter->id)
                ->where('tables.0.waiter.name', $waiter->name)
                ->where('assignment.me', $waiter->id));
    }

    // ── the setting ──────────────────────────────────────────────────────────

    public function test_turning_the_feature_off_frees_open_tables_and_blocks_changes(): void
    {
        $waiter = $this->staff();
        $open = $this->openTable($this->restaurant);
        $closed = $this->closedTable($this->restaurant);
        RestaurantTable::whereIn('id', [$open->id, $closed->id])->update(['waiter_id' => $waiter->id]);

        $this->turn(false);

        $this->assertFalse($this->restaurant->fresh()->table_assignment_enabled);
        $this->assertNull($this->waiterOfTable($open));
        // Who served a closed table stays on record.
        $this->assertSame($waiter->id, $this->waiterOfTable($closed));

        $this->actingAs($waiter)->patch(route('restaurant.tables.claim', $open))->assertForbidden();
        $this->actingAs($this->restaurant)->put(route('restaurant.tables.assign', $open), ['waiter_id' => $waiter->id])->assertForbidden();
        $this->assertNull($this->waiterOfTable($open));

        $this->turn(true);
        $this->actingAs($waiter)->patch(route('restaurant.tables.claim', $open));
        $this->assertSame($waiter->id, $this->waiterOfTable($open));
    }

    public function test_only_the_owner_changes_the_setting(): void
    {
        $manager = $this->staff([], StaffRole::MANAGER);

        $this->actingAs($manager)
            ->put(route('restaurant.establishment.service.update'), ['table_assignment_enabled' => false])
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->restaurant)
            ->put(route('restaurant.establishment.service.update'), ['table_assignment_enabled' => 'talvez'])
            ->assertSessionHasErrors('table_assignment_enabled');

        $this->assertTrue($this->restaurant->fresh()->table_assignment_enabled);
        $this->actingAs($this->restaurant)->get(route('restaurant.establishment.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('tableAssignmentEnabled', true));
    }

    // ── elsewhere ────────────────────────────────────────────────────────────

    public function test_a_member_who_leaves_the_team_leaves_their_tables_free(): void
    {
        $waiter = $this->staff();
        $table = $this->openTable($this->restaurant);
        $other = $this->openTable($this->restaurant);
        $colleague = $this->staff();
        RestaurantTable::whereKey($table->id)->update(['waiter_id' => $waiter->id]);
        RestaurantTable::whereKey($other->id)->update(['waiter_id' => $colleague->id]);

        $this->actingAs($this->restaurant)->delete(route('restaurant.waiters.destroy', $waiter));

        $this->assertNull($this->waiterOfTable($table));
        $this->assertSame($colleague->id, $this->waiterOfTable($other));
    }

    public function test_the_calls_board_shows_whose_table_called(): void
    {
        $waiter = $this->staff(['tables' => 'write', 'calls' => 'write']);
        $table = $this->openTable($this->restaurant);
        RestaurantTable::whereKey($table->id)->update(['waiter_id' => $waiter->id]);
        TableCall::open($table, TableCall::WAITER, $this->customer()->id);

        $this->actingAs($waiter)->get(route('restaurant.calls.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('calls.0.waiter', ['id' => $waiter->id, 'name' => $waiter->name])
                ->where('assignment.canServe', true));
    }
}
