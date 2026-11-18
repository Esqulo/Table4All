<?php

namespace Tests\Feature;

use App\Mail\WaiterInvitationMail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * Everything the server says follows the language picked in the interface
 * (the `locale` cookie written by resources/js/i18n.ts), defaulting to pt_BR.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function failLogin(?string $locale): TestResponse
    {
        $user = User::factory()->create();
        $request = $locale === null ? $this : $this->withUnencryptedCookie('locale', $locale);

        return $request->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // ── which language ───────────────────────────────────────────────────────

    public function test_login_error_is_in_portuguese_by_default(): void
    {
        $this->failLogin(null)->assertSessionHasErrors(['email' => 'As credenciais fornecidas não correspondem aos nossos registros.']);
    }

    public function test_login_error_follows_the_interface_language(): void
    {
        $this->failLogin('en')->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    }

    public function test_unsupported_locale_cookie_is_ignored(): void
    {
        foreach (['fr', '../../etc/passwd', ''] as $locale) {
            App::setLocale('pt_BR');
            $this->failLogin($locale)->assertSessionHasErrors(['email' => 'As credenciais fornecidas não correspondem aos nossos registros.']);
        }
    }

    public function test_page_language_attribute_follows_the_interface_language(): void
    {
        $this->withUnencryptedCookie('locale', 'en')->get(route('home'))->assertSee('<html lang="en"', false);
        $this->withUnencryptedCookie('locale', 'pt_BR')->get(route('home'))->assertSee('<html lang="pt-BR"', false);
    }

    // ── what gets translated ─────────────────────────────────────────────────

    public function test_validation_messages_use_readable_field_names(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($restaurant)->post(route('restaurant.tables.store'), ['title' => ''])
            ->assertSessionHasErrors(['title' => 'O campo nome da mesa é obrigatório.']);

        $this->actingAs($restaurant)->withUnencryptedCookie('locale', 'en')->post(route('restaurant.tables.store'), ['title' => ''])
            ->assertSessionHasErrors(['title' => 'The title field is required.']);
    }

    public function test_app_messages_exist_in_both_languages(): void
    {
        $restaurant = $this->restaurant();
        $waiter = $this->waiterOf($restaurant);

        $this->actingAs($restaurant)->post(route('restaurant.waiters.store'), ['email' => $waiter->email])
            ->assertSessionHasErrors(['email' => 'Este e-mail já é um garçom ativo neste restaurante.']);

        $this->actingAs($restaurant)->withUnencryptedCookie('locale', 'en')
            ->post(route('restaurant.waiters.store'), ['email' => $waiter->email])
            ->assertSessionHasErrors(['email' => 'This email already belongs to an active waiter at this restaurant.']);
    }

    public function test_account_emails_are_translated(): void
    {
        $user = User::factory()->unverified()->create();

        $verify = (new VerifyEmail)->toMail($user);
        $this->assertSame('Confirme seu endereço de e-mail', $verify->subject);
        $this->assertSame('Confirmar e-mail', $verify->actionText);

        $reset = (new ResetPassword('token'))->toMail($user);
        $this->assertSame('Redefina sua senha', $reset->subject);
        $this->assertStringContainsString('minutos', implode(' ', array_merge($reset->introLines, $reset->outroLines)));

        $html = (string) $verify->render();
        $this->assertStringContainsString('Olá!', $html);
        $this->assertStringContainsString('Atenciosamente,', $html);
        $this->assertStringContainsString('copie e cole o endereço abaixo', $html);
    }

    public function test_waiter_invitation_is_sent_in_the_restaurants_language(): void
    {
        Mail::fake();
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);

        $this->actingAs($restaurant)->withUnencryptedCookie('locale', 'en')
            ->post(route('restaurant.waiters.store'), ['email' => 'novo@bar.test']);

        Mail::assertSent(WaiterInvitationMail::class, function (WaiterInvitationMail $mail) {
            return $mail->envelope()->subject === 'Invitation to join as a waiter — Bar do Zé'
                && str_contains($mail->render(), 'Accept invitation');
        });

        App::setLocale('pt_BR');
        Mail::assertSent(WaiterInvitationMail::class, function (WaiterInvitationMail $mail) {
            return $mail->envelope()->subject === 'Convite para ser garçom — Bar do Zé'
                && str_contains($mail->render(), 'Aceitar convite');
        });
    }

    public function test_error_pages_are_translated(): void
    {
        // Rendered by the app's layout now (it adds a class attribute), so only the lang is checked.
        $this->get('/pagina-que-nao-existe')->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertSee('<html lang="pt-BR"', false);
        $this->withUnencryptedCookie('locale', 'en')->get('/pagina-que-nao-existe')->assertNotFound()->assertSee('Not Found');
    }

    public function test_closing_a_closed_table_explains_why_in_portuguese(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->closedTable($restaurant);

        $this->actingAs($restaurant)->patch(route('restaurant.tables.close', $table))
            ->assertStatus(422)
            ->assertSee('Esta mesa já foi fechada.');
    }

    // ── completeness (fails when a new untranslated string shows up) ─────────

    public function test_portuguese_framework_files_cover_every_framework_key(): void
    {
        $frameworkDir = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

        foreach (['auth', 'pagination', 'passwords', 'validation'] as $group) {
            $missing = array_diff_key(
                $this->flatten(require "{$frameworkDir}/{$group}.php"),
                $this->flatten(require lang_path("pt_BR/{$group}.php")),
            );
            unset($missing['custom.attribute-name.rule-name']);

            $this->assertSame([], array_keys($missing), "lang/pt_BR/{$group}.php is missing keys");
        }
    }

    public function test_every_user_facing_string_from_laravel_fortify_and_passkeys_is_translated(): void
    {
        $pt = $this->readJson(lang_path('pt_BR.json'));
        $sources = [
            'vendor/laravel/fortify/src',
            'vendor/laravel/passkeys/src',
            'vendor/laravel/framework/src/Illuminate/Auth/Notifications',
            'vendor/laravel/framework/src/Illuminate/Notifications/resources',
            'vendor/laravel/framework/src/Illuminate/Mail/resources',
            'vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views',
        ];

        $strings = [];
        foreach ($sources as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                preg_match_all(
                    "/(?:__|Lang::get|@lang|InvalidPasskeyException::make)\\(\\s*'([A-Z][^']*)'/",
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                );
                array_push($strings, ...$matches[1]);
            }
        }

        $missing = array_values(array_diff(array_unique($strings), array_keys($pt)));

        $this->assertNotEmpty($strings);
        $this->assertSame([], $missing, 'Add these strings to lang/pt_BR.json');
    }

    public function test_every_translation_key_used_by_the_app_exists_in_both_languages(): void
    {
        $json = ['pt_BR' => $this->readJson(lang_path('pt_BR.json')), 'en' => $this->readJson(lang_path('en.json'))];

        $keys = [];
        foreach (['app', 'routes', 'resources/views'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                preg_match_all("/(?:__|trans)\\(\\s*'([a-z_]+\\.[a-z_.]+)'/", (string) file_get_contents($file->getPathname()), $matches);
                array_push($keys, ...$matches[1]);
            }
        }

        foreach (array_unique($keys) as $key) {
            foreach (['pt_BR', 'en'] as $locale) {
                $found = array_key_exists($key, $json[$locale]) || __($key, [], $locale) !== $key;
                $this->assertTrue($found, "Translation key '{$key}' is missing for {$locale}");
            }
        }
    }

    public function test_frontend_locales_have_the_same_keys(): void
    {
        $pt = $this->flatten($this->readJson(resource_path('js/locales/pt_BR.json')));
        $en = $this->flatten($this->readJson(resource_path('js/locales/en.json')));

        $this->assertSame([], array_keys(array_diff_key($pt, $en)), 'Missing in en.json');
        $this->assertSame([], array_keys(array_diff_key($en, $pt)), 'Missing in pt_BR.json');
    }

    public function test_frontend_plurals_use_the_suffixes_i18next_understands(): void
    {
        foreach (['pt_BR', 'en'] as $locale) {
            $keys = array_keys($this->flatten($this->readJson(resource_path("js/locales/{$locale}.json"))));

            $this->assertSame([], preg_grep('/_plural$/', $keys), "{$locale}.json: use _one/_other instead of _plural");
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, "{$prefix}{$key}.");
            } else {
                $flat["{$prefix}{$key}"] = $value;
            }
        }

        return $flat;
    }
}
