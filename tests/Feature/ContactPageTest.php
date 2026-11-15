<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactPageTest extends TestCase
{
    public function test_contact_page_is_public_and_shows_the_contact_email(): void
    {
        $this->get(route('contact'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('contact')
                ->where('email', config('app.contact_email'))
                ->where('email', fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            );
    }
}
