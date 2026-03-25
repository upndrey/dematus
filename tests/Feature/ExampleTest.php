<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_route_renders_the_stratz_page(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Stratz')
                ->has('heroes'));
    }

    public function test_legacy_stratz_route_redirects_to_home(): void
    {
        $response = $this->get('/stratz');

        $response->assertRedirect(route('home'));
    }
}
