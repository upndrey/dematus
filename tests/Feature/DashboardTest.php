<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_redirected_to_home_from_the_dashboard_route(): void
    {
        $response = $this
            ->withSession([config('static-auth.session_key') => true])
            ->get(route('dashboard'));

        $response->assertRedirect(route('home'));
    }
}
