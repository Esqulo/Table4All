<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_page_is_public(): void
    {
        $this->get('/parceiro')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('partner'));
    }

    public function test_partner_page_opens_for_signed_in_users_too(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::RESTAURANT]);

        $this->actingAs($user)
            ->get(route('partner'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('partner'));
    }
}
