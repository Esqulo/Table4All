<?php

namespace Tests\Feature\Restaurant;

use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function currentCode(string $secret = self::SECRET): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    // ─── Separate sign-in ─────────────────────────────────────────────────────

    public function test_admins_cannot_sign_in_on_the_regular_login(): void
    {
        $admin = $this->admin();

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_regular_accounts_cannot_sign_in_on_the_admin_login(): void
    {
        foreach ([$this->customer(), $this->restaurant()] as $user) {
            $this->post(route('admin.login.store'), ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->assertNull(session('admin_login.id'));
        }
    }

    public function test_admin_signs_in_with_password_then_the_authenticator_code(): void
    {
        $admin = $this->admin();

        $this->get(route('admin.login'))->assertInertia(fn (Assert $page) => $page->component('admin/auth/login'));

        // The password alone doesn't sign in.
        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.login.code'));
        $this->assertGuest();
        $this->get(route('admin.overview'))->assertRedirect(route('admin.login'));

        $this->get(route('admin.login.code'))->assertInertia(fn (Assert $page) => $page->component('admin/auth/two-factor'));

        $this->post(route('admin.login.code.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('admin.login.code.store'), ['code' => $this->currentCode()])
            ->assertRedirect(route('admin.overview'));
        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull($admin->refresh()->last_login_at);
    }

    public function test_a_wrong_password_never_reaches_the_code_step(): void
    {
        $admin = $this->admin();

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'errada'])
            ->assertSessionHasErrors('email');

        $this->get(route('admin.login.code'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.login.code.store'), ['code' => $this->currentCode()])->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_the_code_step_expires(): void
    {
        $admin = $this->admin();
        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password']);

        $this->travel(6)->minutes();

        $this->post(route('admin.login.code.store'), ['code' => $this->currentCode()])->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $admin = $this->admin();

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password']);
        $this->post(route('admin.login.code.store'), ['recovery_code' => 'recovery-code-1'])->assertRedirect(route('admin.overview'));
        $this->assertAuthenticatedAs($admin);
        $this->assertNotContains('recovery-code-1', $admin->refresh()->recoveryCodes());

        auth()->logout();
        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password']);
        $this->post(route('admin.login.code.store'), ['recovery_code' => 'recovery-code-1'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_wrong_passwords_and_codes_are_throttled(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'errada']);
        }
        // Even the right password is refused while locked.
        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertNull(session('admin_login.id'));

        $other = User::factory()->admin()->create();
        $this->post(route('admin.login.store'), ['email' => $other->email, 'password' => 'password']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.login.code.store'), ['code' => '000000']);
        }
        $this->post(route('admin.login.code.store'), ['code' => $this->currentCode()])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    // ─── Authenticator setup ──────────────────────────────────────────────────

    public function test_an_admin_without_the_authenticator_must_set_it_up_first(): void
    {
        $admin = User::factory()->create(['account_type' => 'admin']);

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.two-factor.setup'));
        $this->assertAuthenticatedAs($admin);

        // Nothing else until it's done.
        $this->get(route('admin.overview'))->assertRedirect(route('admin.two-factor.setup'));
        $this->get(route('admin.restaurants.index'))->assertRedirect(route('admin.two-factor.setup'));

        $this->get(route('admin.two-factor.setup'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/auth/two-factor-setup')->has('qrCodeSvg')->has('secret'));

        $secret = decrypt((string) $admin->refresh()->two_factor_secret);

        $this->post(route('admin.two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($admin->refresh()->two_factor_confirmed_at);

        $this->post(route('admin.two-factor.confirm'), ['code' => $this->currentCode($secret)])
            ->assertRedirect(route('admin.two-factor.recovery-codes'));
        $this->assertNotNull($admin->refresh()->two_factor_confirmed_at);

        $this->get(route('admin.two-factor.recovery-codes'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/auth/recovery-codes')->has('codes', 8));
        // Shown once.
        $this->get(route('admin.two-factor.recovery-codes'))->assertRedirect(route('admin.overview'));

        $this->get(route('admin.overview'))->assertOk();
    }

    public function test_admins_cannot_turn_the_authenticator_off_in_settings(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('two-factor.disable'))
            ->assertForbidden();
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.enable'))
            ->assertForbidden();

        $this->assertNotNull($admin->refresh()->two_factor_confirmed_at);
        $this->assertSame(self::SECRET, decrypt((string) $admin->two_factor_secret));
    }

    public function test_make_admin_can_reset_a_lost_authenticator(): void
    {
        $admin = $this->admin();

        $this->artisan('app:make-admin', ['email' => $admin->email, '--reset-two-factor' => true])->assertSuccessful();

        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
    }

    // ─── Invitations ──────────────────────────────────────────────────────────

    public function test_an_admin_invites_another_who_sets_up_the_authenticator(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $token = null;

        $this->actingAs($admin)->post(route('admin.admins.store'), ['email' => 'nova@admin.com'])
            ->assertSessionHasNoErrors();
        Mail::assertSent(AdminInvitationMail::class, function (AdminInvitationMail $mail) use (&$token) {
            $token = $mail->token;

            return $mail->hasTo('nova@admin.com');
        });

        $invitation = AdminInvitation::sole();
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', (string) $token), $invitation->token_hash);

        $this->actingAs($admin)->get(route('admin.admins.index'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/admins')->has('admins', 1)->has('invitations', 1));

        auth()->logout();

        $this->get(route('admin.invitation.show', $token))
            ->assertInertia(fn (Assert $page) => $page->where('valid', true)->where('email', 'nova@admin.com'));

        $this->post(route('admin.invitation.accept', $token), [
            'name' => 'Nova Admin',
            'password' => 'uma-senha-bem-longa-123',
            'password_confirmation' => 'uma-senha-bem-longa-123',
        ])->assertRedirect(route('admin.two-factor.setup'));

        $new = User::where('email', 'nova@admin.com')->sole();
        $this->assertSame('admin', $new->account_type->value);
        $this->assertNotNull($new->email_verified_at);
        $this->assertAuthenticatedAs($new);
        $this->assertNotNull($invitation->refresh()->accepted_at);

        // The link works once.
        auth()->logout();
        $this->get(route('admin.invitation.show', $token))->assertInertia(fn (Assert $page) => $page->where('valid', false));
        $this->post(route('admin.invitation.accept', $token), [
            'name' => 'Outra', 'password' => 'uma-senha-bem-longa-123', 'password_confirmation' => 'uma-senha-bem-longa-123',
        ])->assertSessionHasErrors('name');
        $this->assertSame(1, User::where('email', 'nova@admin.com')->count());
    }

    public function test_invitations_expire_and_can_be_revoked(): void
    {
        Mail::fake();
        $admin = $this->admin();

        [$expired, $expiredToken] = AdminInvitation::issue('a@admin.com', $admin);
        $this->travel(AdminInvitation::VALID_HOURS + 1)->hours();
        $this->post(route('admin.invitation.accept', $expiredToken), [
            'name' => 'A', 'password' => 'uma-senha-bem-longa-123', 'password_confirmation' => 'uma-senha-bem-longa-123',
        ])->assertSessionHasErrors('name');

        [$revoked, $revokedToken] = AdminInvitation::issue('b@admin.com', $admin);
        $this->actingAs($admin)->delete(route('admin.admins.invitations.destroy', $revoked))->assertSessionHasNoErrors();
        auth()->logout();
        $this->post(route('admin.invitation.accept', $revokedToken), [
            'name' => 'B', 'password' => 'uma-senha-bem-longa-123', 'password_confirmation' => 'uma-senha-bem-longa-123',
        ])->assertSessionHasErrors('name');

        $this->assertFalse(User::whereIn('email', ['a@admin.com', 'b@admin.com'])->exists());
    }

    public function test_only_admins_invite_and_never_to_an_existing_account(): void
    {
        Mail::fake();
        $customer = $this->customer();

        $this->actingAs($customer)->post(route('admin.admins.store'), ['email' => 'x@admin.com'])->assertForbidden();
        $this->actingAs($this->admin())->post(route('admin.admins.store'), ['email' => $customer->email])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, AdminInvitation::count());
        Mail::assertNothingSent();
    }
}
