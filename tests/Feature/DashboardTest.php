<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_staff_accounts_can_visit_the_dashboard()
    {
        foreach (['restaurant', 'admin'] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))
                ->get(route('dashboard'))
                ->assertOk();
        }
    }

    public function test_customers_land_on_the_social_feed()
    {
        $this->actingAs(User::factory()->create(['account_type' => 'customer']))
            ->get(route('dashboard'))
            ->assertRedirect(route('social.feed'));
    }
}
