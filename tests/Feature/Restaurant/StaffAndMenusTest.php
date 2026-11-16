<?php

namespace Tests\Feature\Restaurant;

use App\Mail\WaiterInvitationMail;
use App\Models\Category;
use App\Models\Menu;
use App\Models\User;
use App\Models\WaiterInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Waiter invitations and removal, and the public/printable menus. */
class StaffAndMenusTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function invitation(User $restaurant, array $attributes = []): WaiterInvitation
    {
        return WaiterInvitation::create([
            'restaurant_id' => $restaurant->id,
            'email' => 'novo@bar.test',
            'token' => Str::random(64),
            ...$attributes,
        ]);
    }

    // ── inviting ─────────────────────────────────────────────────────────────

    public function test_invitation_is_emailed(): void
    {
        Mail::fake();
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.waiters.store'), ['email' => 'novo@bar.test'])
            ->assertRedirect(route('restaurant.waiters.index'));

        $this->assertSame('novo@bar.test', WaiterInvitation::sole()->email);
        Mail::assertSent(WaiterInvitationMail::class, fn ($mail) => $mail->hasTo('novo@bar.test'));
    }

    public function test_reinviting_replaces_the_pending_invitation(): void
    {
        Mail::fake();
        $restaurant = $this->restaurant();
        $old = $this->invitation($restaurant);

        $this->actingAs($restaurant)->post(route('restaurant.waiters.store'), ['email' => 'novo@bar.test']);

        $this->assertModelMissing($old);
        $this->assertSame(1, WaiterInvitation::count());
    }

    public function test_inviting_a_current_waiter_is_refused(): void
    {
        Mail::fake();
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);

        $this->actingAs($restaurant)->post(route('restaurant.waiters.store'), ['email' => $waiter->email])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_invitation_email_is_validated(): void
    {
        Mail::fake();

        $this->actingAs($this->restaurant())->post(route('restaurant.waiters.store'), ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_staff_page_lists_only_live_invitations(): void
    {
        $restaurant = $this->restaurant();
        $this->invitation($restaurant, ['email' => 'pendente@bar.test']);
        $this->invitation($restaurant, ['email' => 'aceito@bar.test', 'accepted_at' => now()]);
        $this->invitation($restaurant, ['email' => 'vencido@bar.test'])->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->invitation($this->restaurant(), ['email' => 'alheio@bar.test']);

        $this->actingAs($restaurant)->get(route('restaurant.waiters.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('invitations', 1)
                ->where('invitations.0.email', 'pendente@bar.test')
            );
    }

    public function test_restaurant_cannot_cancel_another_restaurants_invitation(): void
    {
        $invitation = $this->invitation($this->restaurant());

        $this->actingAs($this->restaurant())->delete(route('restaurant.waiters.cancel-invitation', $invitation))->assertForbidden();

        $this->assertModelExists($invitation);
    }

    // ── accepting ────────────────────────────────────────────────────────────

    public function test_accepting_creates_a_verified_waiter_and_logs_in(): void
    {
        $restaurant = $this->restaurant();
        $invitation = $this->invitation($restaurant);

        $this->get(route('waiter.invite.show', $invitation->token))->assertOk();
        $this->post(route('waiter.invite.accept', $invitation->token), [
            'name' => 'Novo Garçom', 'password' => 'segredo123', 'password_confirmation' => 'segredo123',
        ])->assertRedirect(route('dashboard'));

        $waiter = User::where('email', 'novo@bar.test')->sole();
        $this->assertSame('waiter', $waiter->account_type->value);
        $this->assertSame($restaurant->id, $waiter->restaurant_id);
        $this->assertNotNull($waiter->email_verified_at);
        $this->assertAuthenticatedAs($waiter);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_invitation_link_works_only_once(): void
    {
        $invitation = $this->invitation($this->restaurant());
        $payload = ['name' => 'A', 'password' => 'segredo123', 'password_confirmation' => 'segredo123'];

        $this->post(route('waiter.invite.accept', $invitation->token), $payload);
        auth()->logout();

        $this->get(route('waiter.invite.show', $invitation->token))->assertNotFound();
        $this->post(route('waiter.invite.accept', $invitation->token), $payload)->assertNotFound();
        $this->assertSame(1, User::where('email', 'novo@bar.test')->count());
    }

    public function test_expired_or_unknown_invitation_is_not_found(): void
    {
        $expired = $this->invitation($this->restaurant());
        $expired->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->get(route('waiter.invite.show', $expired->token))->assertNotFound();
        $this->get(route('waiter.invite.show', 'nope'))->assertNotFound();
    }

    public function test_invitation_for_an_existing_account_cannot_create_a_duplicate(): void
    {
        User::factory()->create(['email' => 'novo@bar.test']);
        $invitation = $this->invitation($this->restaurant());

        $this->get(route('waiter.invite.show', $invitation->token))
            ->assertInertia(fn (Assert $page) => $page->where('email_taken', true));
        $this->post(route('waiter.invite.accept', $invitation->token), [
            'name' => 'A', 'password' => 'segredo123', 'password_confirmation' => 'segredo123',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'novo@bar.test')->count());
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_acceptance_requires_a_confirmed_password_of_eight_characters(): void
    {
        $invitation = $this->invitation($this->restaurant());

        $this->post(route('waiter.invite.accept', $invitation->token), [
            'name' => 'A', 'password' => 'curta', 'password_confirmation' => 'curta',
        ])->assertSessionHasErrors('password');
        $this->post(route('waiter.invite.accept', $invitation->token), [
            'name' => 'A', 'password' => 'segredo123', 'password_confirmation' => 'outra1234',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    // ── removing ─────────────────────────────────────────────────────────────

    public function test_removed_waiter_becomes_a_customer(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);

        $this->actingAs($restaurant)->delete(route('restaurant.waiters.destroy', $waiter));

        $waiter->refresh();
        $this->assertSame('customer', $waiter->account_type->value);
        $this->assertNull($waiter->restaurant_id);
    }

    public function test_restaurant_cannot_remove_another_restaurants_waiter(): void
    {
        $owner = $this->restaurant();
        $waiter = $this->waiterOf($owner);

        $this->actingAs($this->restaurant())->delete(route('restaurant.waiters.destroy', $waiter))->assertForbidden();

        $this->assertSame($owner->id, $waiter->fresh()->restaurant_id);
    }

    // ── menus ────────────────────────────────────────────────────────────────

    public function test_public_menu_lists_only_that_restaurants_live_products(): void
    {
        $restaurant = $this->restaurant();
        $drinks = Category::create(['name' => 'Bebidas']);
        Category::create(['name' => 'Sobremesas']); // unused: must not appear
        $this->product($restaurant, ['name' => 'Chopp', 'category_id' => $drinks->id]);
        $this->product($restaurant, ['name' => 'Fora do cardápio'])->delete();
        $this->product($this->restaurant(), ['name' => 'De outro bar']);
        $menu = Menu::create(['user_id' => $restaurant->id, 'name' => 'Principal']);

        $this->get(route('menu.show', $menu))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('menu/show')
                ->has('products', 1)
                ->where('products.0.name', 'Chopp')
                ->has('categories', 1)
                ->where('categories.0.name', 'Bebidas')
            );
    }

    public function test_deleted_menu_link_is_not_found(): void
    {
        $menu = Menu::create(['user_id' => $this->restaurant()->id, 'name' => 'Antigo']);
        $menu->delete();

        $this->get(route('menu.show', $menu->id))->assertNotFound();
    }

    public function test_menu_name_is_required_and_list_is_per_restaurant(): void
    {
        $restaurant = $this->restaurant();
        Menu::create(['user_id' => $this->restaurant()->id, 'name' => 'Alheio']);

        $this->actingAs($restaurant)->post(route('restaurant.menus.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($restaurant)->post(route('restaurant.menus.store'), ['name' => 'Almoço']);

        $this->actingAs($restaurant)->get(route('restaurant.menus.index'))
            ->assertInertia(fn (Assert $page) => $page->has('menus', 1)->where('menus.0.name', 'Almoço'));
    }

    // ── print layout ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function printLayout(array $overrides = []): array
    {
        return [
            'template' => 'chalkboard',
            'item_style' => 'leaders',
            'paper' => 'A3',
            'landscape' => false,
            'columns' => 2,
            'heading_font' => 'caveat',
            'body_font' => 'patrick',
            'accent' => '#f2c94c',
            'scale' => 1.05,
            'grouped' => true,
            'show_descriptions' => true,
            'show_images' => false,
            'section_page_break' => false,
            'show_header' => true,
            'show_page_numbers' => true,
            'title' => 'Cardápio de inverno',
            'subtitle' => null,
            'footer' => 'Taxa de serviço não inclusa',
            'sections' => [
                ['key' => -1, 'title' => 'Petiscos', 'style' => 'grid', 'hidden' => false, 'products' => [3, 1]],
            ],
            'flat_order' => [3, 1],
            'hidden_products' => [1],
            ...$overrides,
        ];
    }

    public function test_print_layout_is_saved_and_returned_to_the_editor(): void
    {
        $restaurant = $this->restaurant();
        $menu = Menu::create(['user_id' => $restaurant->id, 'name' => 'Principal']);

        $this->actingAs($restaurant)
            ->put(route('restaurant.menus.layout.update', $menu), ['layout' => $this->printLayout()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('chalkboard', $menu->fresh()->layout['template']);
        $this->assertSame('grid', $menu->fresh()->layout['sections'][0]['style']);

        $this->actingAs($restaurant)->get(route('restaurant.menus.print', $menu))
            ->assertInertia(fn (Assert $page) => $page
                ->component('restaurant/menus/print')
                ->where('layout.template', 'chalkboard')
                ->where('layout.paper', 'A3')
                ->where('layout.hidden_products', [1])
            );
    }

    public function test_print_layout_is_not_exposed_on_the_public_menu(): void
    {
        $restaurant = $this->restaurant();
        $menu = Menu::create(['user_id' => $restaurant->id, 'name' => 'Principal', 'layout' => $this->printLayout()]);

        $this->get(route('menu.show', $menu))
            ->assertInertia(fn (Assert $page) => $page->missing('menu.layout'));
    }

    public function test_print_layout_rejects_unknown_values(): void
    {
        $restaurant = $this->restaurant();
        $menu = Menu::create(['user_id' => $restaurant->id, 'name' => 'Principal']);

        $this->actingAs($restaurant)
            ->put(route('restaurant.menus.layout.update', $menu), ['layout' => $this->printLayout([
                'template' => 'neon',
                'heading_font' => 'comic-sans',
                'accent' => 'red',
                'columns' => 5,
                'scale' => 3,
                'sections' => [['key' => 1, 'style' => 'carousel', 'hidden' => false, 'products' => []]],
            ])])
            ->assertSessionHasErrors([
                'layout.template', 'layout.heading_font', 'layout.accent', 'layout.columns', 'layout.scale',
                'layout.sections.0.style',
            ]);

        $this->assertNull($menu->fresh()->layout);
    }

    public function test_print_layout_belongs_to_the_menus_restaurant(): void
    {
        $owner = $this->restaurant();
        $menu = Menu::create(['user_id' => $owner->id, 'name' => 'Principal']);

        $this->actingAs($this->restaurant())
            ->put(route('restaurant.menus.layout.update', $menu), ['layout' => $this->printLayout()])
            ->assertForbidden();
        $this->actingAs($this->waiterOf($owner))
            ->put(route('restaurant.menus.layout.update', $menu), ['layout' => $this->printLayout()])
            ->assertRedirect(route('dashboard')); // waiters don't edit menus

        $this->assertNull($menu->fresh()->layout);
    }
}
