<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_route_renders_the_stratz_page(): void
    {
        $response = $this
            ->withSession([config('static-auth.session_key') => true])
            ->get(route('home'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Stratz')
                ->has('heroes'));
    }

    public function test_legacy_stratz_route_redirects_to_home(): void
    {
        $response = $this
            ->withSession([config('static-auth.session_key') => true])
            ->get('/stratz');

        $response->assertRedirect(route('home'));
    }

    public function test_home_route_redirects_guests_to_login(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }
}
