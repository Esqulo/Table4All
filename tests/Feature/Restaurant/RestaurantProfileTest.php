<?php

namespace Tests\Feature\Restaurant;

use App\Models\Menu;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\User;
use App\Support\OpeningHours;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Public profile of a restaurant at /vempro/{handle}, its @handle and visibility. */
class RestaurantProfileTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hours(User $restaurant, int $weekday, string $opens, string $closes): void
    {
        RestaurantOpeningHour::create(['user_id' => $restaurant->id, 'weekday' => $weekday, 'opens_at' => $opens, 'closes_at' => $closes]);
    }

    /** Wall-clock time in the business timezone. */
    private function at(string $datetime): void
    {
        Carbon::setTestNow(Carbon::parse($datetime, config('app.business_timezone')));
    }

    private function profile(User $restaurant): TestResponse
    {
        return $this->get(route('restaurant-profile.show', $restaurant->handle));
    }

    private function saveProfile(User $user, array $data): TestResponse
    {
        return $this->actingAs($user)
            ->from(route('restaurant.establishment.edit'))
            ->put(route('restaurant.establishment.profile.update'), [
                'handle' => $user->handle,
                'profile_public' => true,
                ...$data,
            ]);
    }

    // ── the page ─────────────────────────────────────────────────────────────

    public function test_guests_see_the_profile_with_only_public_fields(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé', 'phone' => '11999990000', 'logo' => 'logos/l.webp', 'banner' => 'banners/b.jpg']);
        $other = $this->restaurant();
        $this->hours($restaurant, 1, '18:00', '23:00');
        $this->hours($other, 2, '11:00', '15:00');
        RestaurantPhoto::create(['user_id' => $restaurant->id, 'path' => 'venue/2.jpg', 'position' => 1]);
        RestaurantPhoto::create(['user_id' => $restaurant->id, 'path' => 'venue/1.jpg', 'position' => 0]);
        RestaurantPhoto::create(['user_id' => $other->id, 'path' => 'venue/x.jpg', 'position' => 0]);
        $menu = Menu::create(['user_id' => $restaurant->id, 'name' => 'Cardápio']);
        Menu::create(['user_id' => $other->id, 'name' => 'Outro']);

        $this->get('/vempro/bar-do-ze')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('profile/show')
                ->where('restaurant', [
                    'id' => $restaurant->id,
                    'name' => 'Bar do Zé',
                    'handle' => 'bar-do-ze',
                    'logo_url' => Storage::disk('public')->url('logos/l.webp'),
                    'banner_url' => Storage::disk('public')->url('banners/b.jpg'),
                ])
                ->has('hours', 1)
                ->where('hours.0', ['weekday' => 1, 'opens_at' => '18:00', 'closes_at' => '23:00'])
                ->has('photos', 2)
                ->where('photos.0.url', Storage::disk('public')->url('venue/1.jpg'))
                ->missing('photos.0.path')
                ->where('menus', [['id' => $menu->id, 'name' => 'Cardápio']])
                ->where('isOwner', false)
                ->where('isPublic', true)
                ->where('url', url('/vempro/bar-do-ze')));
    }

    public function test_page_does_not_leak_private_data(): void
    {
        $restaurant = $this->restaurant(['phone' => '11999990000']);

        $this->profile($restaurant)
            ->assertOk()
            ->assertDontSee($restaurant->email, false)
            ->assertDontSee('11999990000', false);
    }

    public function test_address_is_case_insensitive_and_redirects_to_the_canonical_one(): void
    {
        $this->restaurant(['name' => 'Bar do Zé']);

        $this->get('/vempro/Bar-Do-Ze')->assertRedirect('/vempro/bar-do-ze')->assertStatus(301);
        $this->get('/vempro/@bar-do-ze')->assertRedirect('/vempro/bar-do-ze');
    }

    public function test_only_restaurant_accounts_have_a_profile(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $customer->forceFill(['handle' => 'cliente'])->save();

        $this->get('/vempro/cliente')->assertNotFound();
        $this->get('/vempro/nao-existe')->assertNotFound();
        $this->get('/vempro/a.b')->assertNotFound();
        $this->assertNull($this->waiterOf($restaurant)->handle);
    }

    public function test_only_the_owner_gets_the_edit_shortcut(): void
    {
        $restaurant = $this->restaurant();
        $assertOwner = fn (bool $expected) => fn (Assert $page) => $page->where('isOwner', $expected);

        $this->actingAs($restaurant)->get(route('restaurant-profile.show', $restaurant->handle))
            ->assertInertia($assertOwner(true));
        $this->actingAs($this->waiterOf($restaurant))->get(route('restaurant-profile.show', $restaurant->handle))
            ->assertInertia($assertOwner(false));
        $this->actingAs($this->restaurant())->get(route('restaurant-profile.show', $restaurant->handle))
            ->assertInertia($assertOwner(false));
    }

    // ── link preview ─────────────────────────────────────────────────────────

    public function test_page_has_link_preview_tags_with_the_banner(): void
    {
        $this->restaurant(['name' => 'Bar do Zé', 'banner' => 'banners/b.jpg', 'logo' => 'logos/l.webp']);

        $response = $this->get('/vempro/bar-do-ze')
            ->assertSee('<meta property="og:title" content="Bar do Zé">', false)
            ->assertSee('<meta property="og:image" content="'.Storage::disk('public')->url('banners/b.jpg').'">', false)
            ->assertSee('<meta property="og:url" content="'.url('/vempro/bar-do-ze').'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);

        // Server rendering (when the SSR server or Vite is up) marks the title with data-inertia; without it, plain.
        $this->assertMatchesRegularExpression('#<title( data-inertia="")?>Bar do Zé - #', (string) $response->getContent());
    }

    public function test_link_preview_falls_back_to_the_logo_and_escapes_the_name(): void
    {
        $this->restaurant(['name' => 'Bar "do" <Zé>', 'logo' => 'logos/l.webp']);

        $this->get('/vempro/bar-do-ze')
            ->assertSee('<meta property="og:image" content="'.Storage::disk('public')->url('logos/l.webp').'">', false)
            ->assertSee('<meta name="twitter:card" content="summary">', false)
            ->assertSee('<meta property="og:title" content="Bar &quot;do&quot; &lt;Zé&gt;">', false)
            ->assertSee('Bar &quot;do&quot; &lt;Zé&gt; - ', false)
            ->assertDontSee('content="Bar "do"', false);
    }

    public function test_other_pages_have_no_link_preview_tags(): void
    {
        $this->get(route('home'))->assertDontSee('og:title', false);
    }

    // ── handle ───────────────────────────────────────────────────────────────

    public function test_new_restaurants_get_a_free_handle_from_their_name(): void
    {
        $this->assertSame('bar-do-ze', $this->restaurant(['name' => 'Bar do Zé'])->handle);
        $this->assertSame('bar-do-ze-2', $this->restaurant(['name' => 'Bar do Zé!'])->handle);
        $this->assertSame('bar-do-ze-3', $this->restaurant(['name' => 'bar DO ze'])->handle);
        $this->assertSame('restaurante', $this->restaurant(['name' => 'Zé'])->handle);
        $this->assertSame(30, strlen($this->restaurant(['name' => str_repeat('Churrascaria ', 5)])->handle));
        $this->assertNull($this->customer()->handle);
    }

    public function test_renaming_the_restaurant_keeps_its_handle(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $restaurant->update(['name' => 'Boteco do Zé']);

        $this->assertSame('bar-do-ze', $restaurant->fresh()->handle);
    }

    public function test_owner_changes_the_handle_and_the_old_address_stops_working(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);

        $this->saveProfile($restaurant, ['handle' => '  @Boteco_Do-Ze '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('restaurant.establishment.edit'));

        $this->assertSame('boteco_do-ze', $restaurant->fresh()->handle);
        $this->get('/vempro/boteco_do-ze')->assertOk();
        $this->get('/vempro/bar-do-ze')->assertNotFound();

        $this->actingAs($restaurant)->get(route('restaurant.establishment.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('handle', 'boteco_do-ze')
                ->where('profilePublic', true)
                ->where('profileBaseUrl', url('/vempro')));
    }

    /** @return array<string, array{string}> */
    public static function invalidHandles(): array
    {
        return [
            'empty' => [''],
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 31)],
            'space inside' => ['bar do ze'],
            'accent' => ['bar-do-zé'],
            'dot' => ['bar.do.ze'],
            'slash' => ['bar/ze'],
            'starts with hyphen' => ['-barze'],
            'ends with underscore' => ['barze_'],
            'double separator' => ['bar--ze'],
            'mixed separators' => ['bar_-ze'],
        ];
    }

    #[DataProvider('invalidHandles')]
    public function test_invalid_handles_are_rejected(string $handle): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);

        $this->saveProfile($restaurant, ['handle' => $handle])->assertSessionHasErrors('handle');

        $this->assertSame('bar-do-ze', $restaurant->fresh()->handle);
    }

    public function test_handle_taken_by_another_restaurant_is_rejected_in_any_case(): void
    {
        $this->restaurant(['name' => 'Bar do Zé']);
        $restaurant = $this->restaurant(['name' => 'Boteco']);

        $this->saveProfile($restaurant, ['handle' => 'BAR-DO-ZE'])->assertSessionHasErrors('handle');
        $this->assertSame('boteco', $restaurant->fresh()->handle);

        // Saving its own handle again is fine.
        $this->saveProfile($restaurant, ['handle' => 'boteco'])->assertSessionHasNoErrors();
    }

    public function test_waiter_cannot_change_the_profile(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);

        $this->actingAs($this->waiterOf($restaurant))
            ->put(route('restaurant.establishment.profile.update'), ['handle' => 'roubado', 'profile_public' => false])
            ->assertRedirect(route('dashboard'));

        $restaurant->refresh();
        $this->assertSame('bar-do-ze', $restaurant->handle);
        $this->assertTrue($restaurant->profile_public);
    }

    // ── visibility ───────────────────────────────────────────────────────────

    public function test_hidden_profile_is_not_found_for_everyone_but_the_owner(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé', 'banner' => 'banners/b.jpg']);

        $this->saveProfile($restaurant, ['profile_public' => false])->assertSessionHasNoErrors();
        $this->assertFalse($restaurant->fresh()->profile_public);

        auth()->logout();
        $this->get('/vempro/bar-do-ze')->assertNotFound();
        $this->actingAs($this->customer())->get('/vempro/bar-do-ze')->assertNotFound();
        $this->actingAs($this->waiterOf($restaurant))->get('/vempro/bar-do-ze')->assertNotFound();
        $this->actingAs($this->restaurant())->get('/vempro/bar-do-ze')->assertNotFound();
        // Even the canonical redirect would reveal that the handle exists.
        auth()->logout();
        $this->get('/vempro/BAR-DO-ZE')->assertNotFound();

        $this->actingAs($restaurant)->get('/vempro/bar-do-ze')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('isPublic', false)->where('isOwner', true))
            ->assertDontSee('og:title', false);
    }

    public function test_owner_can_publish_the_profile_again(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé', 'profile_public' => false]);

        $this->saveProfile($restaurant, ['profile_public' => true])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get('/vempro/bar-do-ze')->assertOk();
    }

    public function test_visibility_is_required_and_boolean(): void
    {
        $restaurant = $this->restaurant();

        $this->saveProfile($restaurant, ['profile_public' => null])->assertSessionHasErrors('profile_public');
        $this->saveProfile($restaurant, ['profile_public' => 'talvez'])->assertSessionHasErrors('profile_public');
    }

    // ── address and iFood ────────────────────────────────────────────────────

    public function test_owner_saves_address_and_ifood_link_shown_on_the_profile(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);

        $this->saveProfile($restaurant, [
            'address' => '  Rua das Flores, 123 - Centro  ',
            'ifood_url' => 'https://www.ifood.com.br/delivery/sao-paulo-sp/bar-do-ze/abc',
        ])->assertSessionHasNoErrors();

        $restaurant->refresh();
        $this->assertSame('Rua das Flores, 123 - Centro', $restaurant->address);
        $this->assertSame('https://www.ifood.com.br/delivery/sao-paulo-sp/bar-do-ze/abc', $restaurant->ifood_url);

        $this->get(route('restaurant.establishment.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('address', 'Rua das Flores, 123 - Centro')
                ->where('ifoodUrl', 'https://www.ifood.com.br/delivery/sao-paulo-sp/bar-do-ze/abc'));

        auth()->logout();
        $this->profile($restaurant)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('address', 'Rua das Flores, 123 - Centro')
                ->where('ifoodUrl', 'https://www.ifood.com.br/delivery/sao-paulo-sp/bar-do-ze/abc'));
    }

    public function test_profile_without_address_or_ifood_sends_nulls(): void
    {
        $restaurant = $this->restaurant();

        $this->profile($restaurant)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('address', null)->where('ifoodUrl', null));
    }

    public function test_ifood_link_without_scheme_gets_https(): void
    {
        $restaurant = $this->restaurant();

        $this->saveProfile($restaurant, ['ifood_url' => 'ifood.com.br/delivery/x/y'])->assertSessionHasNoErrors();

        $this->assertSame('https://ifood.com.br/delivery/x/y', $restaurant->fresh()->ifood_url);
    }

    /** @return array<string, array{string}> */
    public static function invalidIfoodLinks(): array
    {
        return [
            'other site' => ['https://example.com/ifood'],
            'lookalike domain' => ['https://ifood.com.br.evil.com/x'],
            'suffix domain' => ['https://notifood.com.br/x'],
            'plain http' => ['http://www.ifood.com.br/delivery/x'],
            'javascript' => ['javascript:alert(1)'],
            'not a link' => ['meu ifood'],
            'too long' => ['https://www.ifood.com.br/'.str_repeat('a', 250)],
        ];
    }

    #[DataProvider('invalidIfoodLinks')]
    public function test_only_https_ifood_links_are_accepted(string $link): void
    {
        $restaurant = $this->restaurant();

        $this->saveProfile($restaurant, ['ifood_url' => $link])->assertSessionHasErrors('ifood_url');

        $this->assertNull($restaurant->fresh()->ifood_url);
    }

    public function test_address_is_limited_in_length(): void
    {
        $restaurant = $this->restaurant();

        $this->saveProfile($restaurant, ['address' => str_repeat('a', 256)])->assertSessionHasErrors('address');

        $this->assertNull($restaurant->fresh()->address);
    }

    public function test_address_and_ifood_can_be_cleared(): void
    {
        $restaurant = $this->restaurant(['address' => 'Rua A, 1', 'ifood_url' => 'https://www.ifood.com.br/x']);

        $this->saveProfile($restaurant, ['address' => '', 'ifood_url' => ''])->assertSessionHasNoErrors();

        $restaurant->refresh();
        $this->assertNull($restaurant->address);
        $this->assertNull($restaurant->ifood_url);
    }

    public function test_publishing_from_the_profile_keeps_address_and_ifood(): void
    {
        $restaurant = $this->restaurant(['profile_public' => false, 'address' => 'Rua A, 1', 'ifood_url' => 'https://www.ifood.com.br/x']);

        // The "make public" shortcut sends only handle and visibility.
        $this->actingAs($restaurant)
            ->put(route('restaurant.establishment.profile.update'), ['handle' => $restaurant->handle, 'profile_public' => true])
            ->assertSessionHasNoErrors();

        $restaurant->refresh();
        $this->assertTrue($restaurant->profile_public);
        $this->assertSame('Rua A, 1', $restaurant->address);
        $this->assertSame('https://www.ifood.com.br/x', $restaurant->ifood_url);
    }

    public function test_waiter_cannot_change_address_or_ifood(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($this->waiterOf($restaurant))
            ->put(route('restaurant.establishment.profile.update'), [
                'handle' => $restaurant->handle,
                'profile_public' => true,
                'address' => 'Outro lugar',
                'ifood_url' => 'https://www.ifood.com.br/outro',
            ])
            ->assertRedirect(route('dashboard'));

        $restaurant->refresh();
        $this->assertNull($restaurant->address);
        $this->assertNull($restaurant->ifood_url);
    }

    // ── open now ─────────────────────────────────────────────────────────────

    public function test_open_state_uses_the_business_timezone(): void
    {
        $restaurant = $this->restaurant();
        $this->hours($restaurant, 1, '18:00', '23:00');

        // Monday 22:30 in São Paulo is already Tuesday 01:30 in UTC.
        $this->at('2026-11-16 22:30');
        $this->profile($restaurant)
            ->assertInertia(fn (Assert $page) => $page->where('isOpen', true)->where('today', 1));

        $this->at('2026-11-16 23:00');
        $this->profile($restaurant)
            ->assertInertia(fn (Assert $page) => $page->where('isOpen', false));
    }

    public function test_open_state_is_unknown_without_hours(): void
    {
        $restaurant = $this->restaurant();

        $this->profile($restaurant)
            ->assertInertia(fn (Assert $page) => $page->where('isOpen', null)->has('hours', 0));
    }

    /** @return array<string, array{string, bool}> */
    public static function moments(): array
    {
        // Friday 18:00–02:00 (overnight), Sunday 11:00–15:00. 2026-11-20 is a Friday.
        return [
            'friday before opening' => ['2026-11-20 17:59', false],
            'friday at opening' => ['2026-11-20 18:00', true],
            'friday late night' => ['2026-11-20 23:59', true],
            'saturday after midnight' => ['2026-11-21 01:59', true],
            'saturday at closing' => ['2026-11-21 02:00', false],
            'saturday evening' => ['2026-11-21 20:00', false],
            'sunday lunch' => ['2026-11-22 12:00', true],
            'sunday at closing' => ['2026-11-22 15:00', false],
            'monday after midnight' => ['2026-11-23 01:00', false],
        ];
    }

    #[DataProvider('moments')]
    public function test_open_state_handles_overnight_shifts(string $moment, bool $open): void
    {
        $hours = [
            ['weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00'],
            ['weekday' => 0, 'opens_at' => '11:00', 'closes_at' => '15:00'],
        ];

        $this->assertSame($open, OpeningHours::isOpenAt($hours, Carbon::parse($moment, 'America/Sao_Paulo')));
    }

    public function test_saturday_overnight_shift_runs_into_sunday(): void
    {
        $hours = [['weekday' => 6, 'opens_at' => '20:00', 'closes_at' => '03:00']];

        $this->assertTrue(OpeningHours::isOpenAt($hours, Carbon::parse('2026-11-22 02:30', 'America/Sao_Paulo')));
        $this->assertFalse(OpeningHours::isOpenAt($hours, Carbon::parse('2026-11-22 03:00', 'America/Sao_Paulo')));
    }
}
