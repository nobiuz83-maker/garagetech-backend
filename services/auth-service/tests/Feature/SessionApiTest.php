<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_the_existing_user_without_exposing_credentials(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertNoContent();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertDontSee('password')
            ->assertDontSee('remember_token');
    }

    public function test_an_unknown_session_is_unauthorized(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_invalid_credentials_are_rejected_without_creating_a_session(): void
    {
        $user = User::factory()->create();

        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'INVALID_CREDENTIALS');

        $this->assertGuest();
    }

    public function test_logout_invalidates_an_authenticated_session(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertNoContent();
        $this->postJson('/logout')->assertNoContent();

        $this->assertGuest();
    }
}
