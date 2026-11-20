<?php

namespace Tests\Feature\Restaurant;

use App\Enums\AccountType;
use App\Enums\StaffFeature;
use App\Enums\StaffRole;
use App\Mail\WaiterInvitationMail;
use App\Models\RestaurantQueue;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\WaiterInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * Each staff member's access per feature (none / read-only / write), the manager role,
 * who manages whom on the team page, and which tables can be deleted.
 */
class StaffPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @param  array<string, string>  $permissions */
    private function member(User $restaurant, array $permissions = [], StaffRole $role = StaffRole::WAITER): User
    {
        $member = $this->waiterOf($restaurant);
        $member->forceFill(['staff_role' => $role, 'staff_permissions' => $permissions])->save();

        return $member->fresh();
    }

    /** @param  array<string, string>  $permissions */
    private function manager(User $restaurant, array $permissions = []): User
    {
        return $this->member($restaurant, $permissions, StaffRole::MANAGER);
    }

    /**
     * Every grantable feature at $default, with $overrides.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function levels(array $overrides = [], string $default = 'none'): array
    {
        $all = ['tables', 'delivery', 'queues', 'products', 'menus', 'sales', 'coupons', 'incentives', 'dashboard', 'finance', 'establishment'];

        return [...array_fill_keys($all, $default), ...$overrides];
    }

    // ── access per feature ───────────────────────────────────────────────────

    public function test_waiters_keep_the_service_screens_by_default(): void
    {
        $restaurant = $this->restaurant();
        // Created before permissions existed: nothing stored.
        $waiter = $this->waiterOf($restaurant);
        $table = $this->openTable($restaurant);

        foreach ([route('restaurant.tables.index'), route('restaurant.tables.show', $table), route('restaurant.delivery.index'), route('restaurant.products.index')] as $url) {
            $this->actingAs($waiter)->get($url)->assertOk();
        }

        $this->actingAs($waiter)->get(route('restaurant.products.create'))->assertRedirect(route('dashboard'));
        $this->actingAs($waiter)->get(route('restaurant.tables.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions.tables', 'write')
                ->where('auth.permissions.products', 'read')
                ->where('auth.permissions.queues', 'none')
                ->where('auth.permissions.staff', 'none')
                ->where('auth.staffRole', 'waiter'));
    }

    public function test_read_only_access_shows_the_pages_but_refuses_changes(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->member($restaurant, ['queues' => 'read', 'products' => 'read']);
        $queue = $this->kitchenQueue($restaurant);

        $this->actingAs($waiter)->get(route('restaurant.queues.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('queues', 1)->where('queues.0.id', $queue->id));
        $this->get(route('restaurant.queues.show', $queue))->assertOk();

        // The forms and every change need writing.
        $this->get(route('restaurant.queues.create'))->assertRedirect(route('dashboard'));
        $this->get(route('restaurant.queues.edit', $queue))->assertRedirect(route('dashboard'));
        $this->from(route('restaurant.queues.index'))
            ->post(route('restaurant.queues.store'), ['name' => 'Bar'])
            ->assertRedirect(route('restaurant.queues.index'))
            ->assertInertiaFlash('toast.message', 'staff.error_no_permission');
        $this->delete(route('restaurant.queues.destroy', $queue));
        $this->put(route('restaurant.queues.update', $queue), ['name' => 'Renomeada']);

        $this->assertSame(['Cozinha'], RestaurantQueue::where('user_id', $restaurant->id)->pluck('name')->all());
        $this->actingAs($waiter)->get(route('restaurant.products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    }

    public function test_write_access_works_on_the_restaurants_own_records(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->member($restaurant, ['queues' => 'write', 'products' => 'write']);

        $this->actingAs($waiter)->get(route('restaurant.queues.create'))->assertOk();
        $this->post(route('restaurant.queues.store'), ['name' => 'Bar'])->assertRedirect(route('restaurant.queues.index'));

        // Created for the restaurant, not for the waiter's own account.
        $this->assertTrue(RestaurantQueue::where('user_id', $restaurant->id)->where('name', 'Bar')->exists());
        $this->assertFalse(RestaurantQueue::where('user_id', $waiter->id)->exists());
        $this->get(route('restaurant.products.index'))->assertInertia(fn (Assert $page) => $page->where('canManage', true));
    }

    public function test_no_access_keeps_the_member_out(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->member($restaurant, ['tables' => 'write']);

        foreach (['restaurant.delivery.index', 'restaurant.queues.index', 'restaurant.products.index', 'restaurant.sales.index', 'restaurant.coupons.index', 'restaurant.incentives.index', 'restaurant.menus.index', 'restaurant.dashboards.index', 'restaurant.finance.show', 'restaurant.establishment.edit', 'restaurant.waiters.index'] as $route) {
            $this->actingAs($waiter)->get(route($route))->assertRedirect(route('dashboard'));
        }

        $this->actingAs($waiter)->get(route('restaurant.tables.index'))->assertOk();
    }

    public function test_taking_access_away_applies_at_once(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->member($restaurant, ['sales' => 'write']);
        $this->actingAs($waiter)->get(route('restaurant.sales.index'))->assertOk();

        $this->actingAs($restaurant)->put(route('restaurant.waiters.update', $waiter), [
            'role' => 'waiter',
            'permissions' => $this->levels(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($waiter->fresh())->get(route('restaurant.sales.index'))->assertRedirect(route('dashboard'));
    }

    public function test_the_venue_and_its_finances_stay_read_only_for_staff_whatever_is_stored(): void
    {
        $restaurant = $this->restaurant(['handle' => 'casa-do-ze', 'address' => 'Rua A, 1']);
        // Even if "write" ended up stored, staff never get past the feature's ceiling.
        $waiter = $this->member($restaurant, ['establishment' => 'write', 'finance' => 'write', 'dashboard' => 'write']);

        $this->actingAs($waiter)->get(route('restaurant.establishment.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // The restaurant's settings, not the waiter's own (empty) account.
                ->where('handle', 'casa-do-ze')
                ->where('address', 'Rua A, 1')
                ->where('auth.permissions.establishment', 'read'));
        $this->get(route('restaurant.finance.show'))->assertOk();

        $this->put(route('restaurant.establishment.profile.update'), ['handle' => 'roubado', 'profile_public' => true])
            ->assertRedirect(route('dashboard'));
        $this->put(route('restaurant.establishment.hours.update'), ['hours' => []])->assertRedirect(route('dashboard'));
        $this->post(route('restaurant.finance.connect'))->assertRedirect(route('dashboard'));
        $this->post(route('restaurant.posts.store'), ['body' => 'Em nome do restaurante'])->assertRedirect(route('dashboard'));

        $this->assertSame('casa-do-ze', $restaurant->fresh()->handle);
        $this->assertDatabaseCount('restaurant_posts', 0);
    }

    // ── managers ─────────────────────────────────────────────────────────────

    public function test_a_manager_sees_every_area_but_writes_only_where_granted(): void
    {
        $restaurant = $this->restaurant();
        $manager = $this->manager($restaurant, ['tables' => 'write']);

        foreach (['restaurant.tables.index', 'restaurant.delivery.index', 'restaurant.queues.index', 'restaurant.products.index', 'restaurant.sales.index', 'restaurant.coupons.index', 'restaurant.incentives.index', 'restaurant.menus.index', 'restaurant.dashboards.index', 'restaurant.finance.show', 'restaurant.establishment.edit', 'restaurant.waiters.index'] as $route) {
            $this->actingAs($manager)->get(route($route))->assertOk();
        }

        $this->actingAs($manager)->get(route('restaurant.tables.create'))->assertOk();
        $this->get(route('restaurant.queues.create'))->assertRedirect(route('dashboard'));
        $this->post(route('restaurant.queues.store'), ['name' => 'Bar']);
        $this->assertDatabaseCount('restaurant_queues', 0);

        $this->get(route('restaurant.tables.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.staffRole', 'manager')
            ->where('auth.permissions.tables', 'write')
            ->where('auth.permissions.queues', 'read')
            ->where('auth.permissions.staff', 'write'));
    }

    public function test_a_manager_never_changes_the_venue_nor_posts_in_its_name(): void
    {
        $restaurant = $this->restaurant(['handle' => 'casa-do-ze']);
        $manager = $this->manager($restaurant, $this->levels(default: 'write'));

        $this->actingAs($manager)
            ->put(route('restaurant.establishment.profile.update'), ['handle' => 'outro', 'profile_public' => false])
            ->assertRedirect(route('dashboard'));
        $this->post(route('restaurant.posts.store'), ['body' => 'Promo'])->assertRedirect(route('dashboard'));
        $this->post(route('restaurant.stories.store'))->assertRedirect(route('dashboard'));
        $this->post(route('restaurant.finance.connect'))->assertRedirect(route('dashboard'));
        $this->get(route('restaurant.finance.refresh'))->assertRedirect(route('dashboard'));

        $this->assertSame('casa-do-ze', $restaurant->fresh()->handle);
        $this->assertTrue($restaurant->fresh()->profile_public);
        $this->assertDatabaseCount('restaurant_posts', 0);
    }

    public function test_a_manager_invites_and_removes_waiters_but_not_managers(): void
    {
        Mail::fake();
        $restaurant = $this->restaurant();
        $manager = $this->manager($restaurant);
        $waiter = $this->member($restaurant);
        $otherManager = $this->manager($restaurant);

        $this->actingAs($manager)->post(route('restaurant.waiters.store'), ['email' => 'novo@example.com'])
            ->assertRedirect(route('restaurant.waiters.index'));
        $invitation = WaiterInvitation::where('email', 'novo@example.com')->firstOrFail();
        $this->assertSame('waiter', $invitation->role);
        // The email speaks for the restaurant, not for the manager.
        Mail::assertSent(WaiterInvitationMail::class, fn (WaiterInvitationMail $mail) => $mail->restaurant->is($restaurant));

        $this->post(route('restaurant.waiters.store'), ['email' => 'chefe@example.com', 'role' => 'manager'])->assertForbidden();
        $this->delete(route('restaurant.waiters.destroy', $otherManager))->assertForbidden();
        $this->delete(route('restaurant.waiters.destroy', $manager))->assertForbidden();
        $this->delete(route('restaurant.waiters.destroy', $waiter))->assertRedirect(route('restaurant.waiters.index'));

        $this->assertSame(AccountType::CUSTOMER, $waiter->fresh()->account_type);
        $this->assertNull($waiter->fresh()->staff_role);
        $this->assertNull($waiter->fresh()->staff_permissions);
        $this->assertSame(AccountType::WAITER, $otherManager->fresh()->account_type);
    }

    public function test_a_manager_grants_waiters_no_more_than_their_own_access(): void
    {
        $restaurant = $this->restaurant();
        $manager = $this->manager($restaurant, ['tables' => 'write']);
        $waiter = $this->member($restaurant);

        // Reading everything and writing tables: fine.
        $this->actingAs($manager)->put(route('restaurant.waiters.update', $waiter), [
            'role' => 'waiter',
            'permissions' => $this->levels(['tables' => 'write'], 'read'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('write', $waiter->fresh()->access(StaffFeature::TABLES)->value);
        $this->assertSame('read', $waiter->fresh()->access(StaffFeature::SALES)->value);

        // Writing promotions, which the manager can only read: refused, nothing changes.
        $this->put(route('restaurant.waiters.update', $waiter), [
            'role' => 'waiter',
            'permissions' => $this->levels(['sales' => 'write']),
        ])->assertSessionHasErrors('permissions.sales');
        $this->assertSame('read', $waiter->fresh()->access(StaffFeature::SALES)->value);

        // Nor can a manager make someone a manager.
        $this->put(route('restaurant.waiters.update', $waiter), [
            'role' => 'manager',
            'permissions' => $this->levels(),
        ])->assertForbidden();
    }

    public function test_only_the_owner_manages_managers(): void
    {
        $restaurant = $this->restaurant();
        $manager = $this->manager($restaurant);
        $other = $this->manager($restaurant);

        $this->actingAs($manager)->put(route('restaurant.waiters.update', $other), ['role' => 'waiter', 'permissions' => $this->levels()])->assertForbidden();
        $this->put(route('restaurant.waiters.update', $manager), ['role' => 'manager', 'permissions' => $this->levels(default: 'write')])->assertForbidden();

        $this->actingAs($manager)->get(route('restaurant.waiters.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManageManagers', false)
                ->where('members', fn ($members) => collect($members)->every(fn ($m) => $m['manageable'] === false)));

        // The owner can demote one and promote a waiter.
        $waiter = $this->member($restaurant);
        $this->actingAs($restaurant)->put(route('restaurant.waiters.update', $other), ['role' => 'waiter', 'permissions' => $this->levels()])->assertSessionHasNoErrors();
        $this->put(route('restaurant.waiters.update', $waiter), ['role' => 'manager', 'permissions' => $this->levels(['menus' => 'write'])])->assertSessionHasNoErrors();

        $this->assertSame(StaffRole::WAITER, $other->fresh()->staff_role);
        $this->assertSame(StaffRole::MANAGER, $waiter->fresh()->staff_role);
        $this->assertSame('write', $waiter->fresh()->access(StaffFeature::MENUS)->value);
    }

    public function test_the_team_page_lists_roles_and_access(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->member($restaurant, ['tables' => 'write', 'dashboard' => 'read']);
        $manager = $this->manager($restaurant);

        $this->actingAs($restaurant)->get(route('restaurant.waiters.index'))
            ->assertInertia(function (Assert $page) use ($waiter, $manager) {
                $members = collect($page->toArray()['props']['members'])->keyBy('id');
                $this->assertSame('waiter', $members[$waiter->id]['role']);
                $this->assertSame('write', $members[$waiter->id]['permissions']['tables']);
                $this->assertSame('none', $members[$waiter->id]['permissions']['sales']);
                $this->assertSame('read', $members[$waiter->id]['access']['dashboard']);
                $this->assertTrue($members[$waiter->id]['manageable']);
                $this->assertSame('manager', $members[$manager->id]['role']);
                // A manager reads everything even with nothing granted.
                $this->assertSame('none', $members[$manager->id]['permissions']['sales']);
                $this->assertSame('read', $members[$manager->id]['access']['sales']);
                $this->assertTrue($page->toArray()['props']['canManageManagers']);
                $this->assertSame('read', collect($page->toArray()['props']['features'])->firstWhere('key', 'finance')['ceiling']);
            });
    }

    public function test_permissions_are_validated(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->member($restaurant);

        $this->actingAs($restaurant)->put(route('restaurant.waiters.update', $waiter), [
            'role' => 'waiter',
            'permissions' => $this->levels(['tables' => 'admin']),
        ])->assertSessionHasErrors('permissions.tables');
        $this->put(route('restaurant.waiters.update', $waiter), [
            'role' => 'chef',
            'permissions' => $this->levels(),
        ])->assertSessionHasErrors('role');
        // Every feature must be given.
        $this->put(route('restaurant.waiters.update', $waiter), [
            'role' => 'waiter',
            'permissions' => ['tables' => 'write'],
        ])->assertSessionHasErrors('permissions.sales');
        // Writing the venue is beyond any staff member.
        $this->put(route('restaurant.waiters.update', $waiter), [
            'role' => 'waiter',
            'permissions' => $this->levels(['establishment' => 'write']),
        ])->assertSessionHasErrors('permissions.establishment');
    }

    public function test_another_restaurants_team_is_out_of_reach(): void
    {
        $restaurant = $this->restaurant();
        $manager = $this->manager($restaurant);
        $foreign = $this->member($this->restaurant());

        $this->actingAs($restaurant)->put(route('restaurant.waiters.update', $foreign), ['role' => 'waiter', 'permissions' => $this->levels()])->assertForbidden();
        $this->actingAs($manager)->delete(route('restaurant.waiters.destroy', $foreign))->assertForbidden();
        // A customer isn't a team member to edit.
        $this->actingAs($restaurant)->put(route('restaurant.waiters.update', $this->customer()), ['role' => 'waiter', 'permissions' => $this->levels()])->assertForbidden();

        $this->assertSame(AccountType::WAITER, $foreign->fresh()->account_type);
    }

    public function test_a_manager_invitation_makes_a_manager(): void
    {
        Mail::fake();
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.waiters.store'), ['email' => 'gerente@example.com', 'role' => 'manager']);
        $invitation = WaiterInvitation::where('email', 'gerente@example.com')->firstOrFail();
        $this->assertSame('manager', $invitation->role);

        auth()->logout();
        $this->post(route('waiter.invite.accept', $invitation->token), [
            'name' => 'Gerente',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('dashboard'));

        $manager = User::where('email', 'gerente@example.com')->firstOrFail();
        $this->assertSame(StaffRole::MANAGER, $manager->staff_role);
        $this->assertTrue($manager->isManager());
        $this->assertSame($restaurant->id, $manager->restaurant_id);
    }

    public function test_a_manager_cannot_cancel_a_managers_invitation(): void
    {
        $restaurant = $this->restaurant();
        $manager = $this->manager($restaurant);
        $forManager = WaiterInvitation::create(['restaurant_id' => $restaurant->id, 'email' => 'a@example.com', 'role' => 'manager', 'token' => str_repeat('a', 64)]);
        $forWaiter = WaiterInvitation::create(['restaurant_id' => $restaurant->id, 'email' => 'b@example.com', 'role' => 'waiter', 'token' => str_repeat('b', 64)]);

        $this->actingAs($manager)->delete(route('restaurant.waiters.cancel-invitation', $forManager))->assertForbidden();
        $this->delete(route('restaurant.waiters.cancel-invitation', $forWaiter))->assertRedirect(route('restaurant.waiters.index'));

        $this->assertModelExists($forManager);
        $this->assertModelMissing($forWaiter);
    }

    // ── deleting tables ──────────────────────────────────────────────────────

    public function test_a_waiter_with_table_writing_deletes_a_table_without_consumption(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->member($restaurant, ['tables' => 'write']);
        $table = $this->openTable($restaurant);

        $this->actingAs($waiter)->get(route('restaurant.tables.show', $table))
            ->assertInertia(fn (Assert $page) => $page->where('deletable', true));
        $this->delete(route('restaurant.tables.destroy', $table))->assertRedirect(route('restaurant.tables.index'));

        $this->assertSoftDeleted($table);
    }

    public function test_reading_tables_does_not_allow_deleting_them(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->member($restaurant, ['tables' => 'read']);
        $table = $this->openTable($restaurant);

        $this->actingAs($waiter)->get(route('restaurant.tables.show', $table))->assertOk();
        $this->delete(route('restaurant.tables.destroy', $table));

        $this->assertNotSoftDeleted($table);
    }

    public function test_a_table_with_consumption_is_never_deleted_not_even_by_the_owner(): void
    {
        $restaurant = $this->restaurant();
        $product = $this->product($restaurant);

        $withLine = $this->openTable($restaurant);
        $this->billLine($withLine, $product, 1, 10.0);
        $withOrder = $this->openTable($restaurant);
        $this->queueItem($withOrder, $product);
        $withPayment = $this->openTable($restaurant);
        $withPayment->payments()->create(['method' => 'cash', 'amount' => 10]);
        $closed = $this->closedTable($restaurant);
        $this->billLine($closed, $product, 2, 10.0);

        foreach ([$withLine, $withOrder, $withPayment, $closed] as $table) {
            $this->actingAs($restaurant)
                ->from(route('restaurant.tables.index'))
                ->delete(route('restaurant.tables.destroy', $table))
                ->assertRedirect(route('restaurant.tables.index'))
                ->assertInertiaFlash('toast.message', 'tables.error_delete_with_consumption');

            $this->assertNotSoftDeleted($table);
        }

        $this->actingAs($restaurant)->get(route('restaurant.tables.show', $withLine))
            ->assertInertia(fn (Assert $page) => $page->where('deletable', false));
    }

    public function test_another_restaurants_table_cannot_be_deleted(): void
    {
        $waiter = $this->member($this->restaurant(), ['tables' => 'write']);
        $foreign = $this->openTable($this->restaurant());

        $this->actingAs($waiter)->delete(route('restaurant.tables.destroy', $foreign))->assertForbidden();

        $this->assertNotSoftDeleted($foreign);
        $this->assertSame(1, RestaurantTable::count());
    }
}
