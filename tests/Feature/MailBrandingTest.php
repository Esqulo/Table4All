<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Tests\TestCase;

/**
 * Every e-mail goes through the Table4All Markdown theme (resources/views/vendor/mail),
 * Laravel's own account e-mails included.
 */
class MailBrandingTest extends TestCase
{
    public function test_account_emails_carry_the_brand(): void
    {
        $user = User::factory()->create();

        foreach ([(new VerifyEmail)->toMail($user), (new ResetPassword('token'))->toMail($user)] as $mail) {
            $html = (string) $mail->render();

            $this->assertStringContainsString(asset('images/brand/email-logo.png'), $html);
            // Theme inlined: brand accent on the card and the deep green button.
            $this->assertStringContainsString('#22b573', $html);
            $this->assertStringContainsString('background-color: #005d35', $html);
            $this->assertStringNotContainsString('laravel.com', $html);
        }
    }

    public function test_the_logo_is_served(): void
    {
        $this->assertFileExists(public_path('images/brand/email-logo.png'));
    }
}
