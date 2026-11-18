<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\RestaurantPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Errors show as a page of the app (resources/js/pages/error.tsx), keeping the navigation. */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_missing_profile_renders_the_error_page_for_visitors(): void
    {
        $this->get('/vempro/perfil')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('error')
                ->where('status', 404)
                ->where('title', 'Página não encontrada')
                ->where('auth.user', null));
    }

    public function test_signed_in_users_keep_their_session_and_navigation(): void
    {
        $customer = $this->customer();

        // Unknown @handle (404 from the controller) and unknown URL (fallback route).
        foreach (['/vempro/perfil', '/nao/existe/mesmo'] as $url) {
            $this->actingAs($customer)->get($url)
                ->assertNotFound()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('error')
                    ->where('auth.user.id', $customer->id));
        }
    }

    public function test_a_missing_record_keeps_the_signed_in_user_too(): void
    {
        // Route model binding fails before HandleInertiaRequests runs; the page still knows the user.
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)
            ->delete(route('restaurant.posts.destroy', 999999))
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('error')
                ->where('auth.user.id', $restaurant->id)
                ->has('sidebarOpen'));
    }

    public function test_forbidden_renders_the_error_page(): void
    {
        $post = RestaurantPost::create(['user_id' => $this->restaurant()->id, 'body' => 'oi']);

        $this->actingAs($this->restaurant())
            ->delete(route('restaurant.posts.destroy', $post))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('error')
                ->where('status', 403)
                ->where('title', 'Acesso negado'));
    }

    public function test_inertia_visits_get_the_error_page_as_a_page(): void
    {
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        $this->get('/vempro/perfil', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertNotFound()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'error')
            ->assertJsonPath('props.status', 404);
    }

    public function test_json_requests_keep_the_json_error(): void
    {
        $this->getJson('/vempro/perfil')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_the_html_shows_the_error_not_a_page_rendered_before_it(): void
    {
        // Inertia caches the rendered page per request scope; a stale one leaked into the error HTML.
        $this->restaurant(['name' => 'Bar Anterior']);
        $this->get('/vempro/bar-anterior')->assertOk();

        $html = $this->withUnencryptedCookie('locale', 'en')->get('/vempro/perfil')->assertNotFound()->getContent();

        preg_match('/data-page="app"[^>]*>(.*?)<\/script>/s', (string) $html, $m);
        $page = json_decode($m[1] ?? '{}', true);

        $this->assertSame('error', $page['component'] ?? null);
        $this->assertSame('Not Found', $page['props']['title'] ?? null);
        $this->assertStringNotContainsString('Bar Anterior', (string) $html);
    }

    public function test_title_follows_the_interface_language(): void
    {
        $this->withUnencryptedCookie('locale', 'en')
            ->get('/vempro/perfil')
            ->assertInertia(fn (Assert $page) => $page->where('title', 'Not Found'));
    }

    public function test_server_errors_use_the_page_in_production_and_the_details_while_debugging(): void
    {
        Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('boom'));

        config(['app.debug' => false]);
        $this->get('/_test/boom')
            ->assertStatus(500)
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 500));

        config(['app.debug' => true]);
        $this->get('/_test/boom')
            ->assertStatus(500)
            ->assertDontSee('"component":"error"', false)
            ->assertSee('boom');
    }

    public function test_expired_page_goes_back_with_a_message(): void
    {
        Route::middleware('web')->post('/_test/expired', fn () => abort(419));

        $this->from('/vempro')
            ->post('/_test/expired')
            ->assertRedirect('/vempro');
    }
}
