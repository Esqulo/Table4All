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

    public function test_restaurants_can_visit_the_dashboard()
    {
        $this->actingAs(User::factory()->create(['account_type' => 'restaurant']))
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_admins_land_on_the_platform_overview()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.overview'));
    }

    public function test_customers_land_on_the_social_feed()
    {
        $this->actingAs(User::factory()->create(['account_type' => 'customer']))
            ->get(route('dashboard'))
            ->assertRedirect(route('social.feed'));
    }
}
