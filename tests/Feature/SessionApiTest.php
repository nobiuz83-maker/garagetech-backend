<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\IsolatedDatabaseTransactions;
use Tests\TestCase;

class SessionApiTest extends TestCase
{
    use IsolatedDatabaseTransactions;

    public function test_valid_credentials_start_a_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertNoContent();
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/v1/clients')->assertOk();
        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        $user = User::factory()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnprocessable();
        $this->assertGuest();
    }
}
