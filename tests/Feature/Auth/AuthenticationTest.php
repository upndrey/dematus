<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_user_can_authenticate_using_static_credentials(): void
    {
        $this->withStaticCredentials();

        $response = $this->post(route('login.store'), [
            'username' => 'admin',
            'password' => 'secret-password',
        ]);

        $response
            ->assertRedirect(route('home', absolute: false))
            ->assertSessionHas(config('static-auth.session_key'), true);
    }

    public function test_user_can_not_authenticate_with_invalid_password(): void
    {
        $this->withStaticCredentials();

        $response = $this->post(route('login.store'), [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertSessionHasErrors('username')
            ->assertSessionMissing(config('static-auth.session_key'));
    }

    public function test_user_can_logout(): void
    {
        $response = $this
            ->withSession([config('static-auth.session_key') => true])
            ->post(route('logout'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionMissing(config('static-auth.session_key'));
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $this->withStaticCredentials();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'username' => 'admin',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post(route('login.store'), [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }

    private function withStaticCredentials(): void
    {
        config()->set('static-auth.username', 'admin');
        config()->set('static-auth.password_hash', Hash::make('secret-password'));
    }
}
