<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_real_csrf_protection(): void
    {
        $this->app['env'] = 'local';
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertStatus(419);
        $this->assertGuest();
    }

    public function test_csrf_bootstrap_emits_host_only_cookies_with_separate_http_only_settings(): void
    {
        config()->set('session.domain', null);
        config()->set('session.path', '/');
        config()->set('session.secure', false);

        $response = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());
        $sessionCookie = $cookies[config('session.cookie')];
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertFalse($cookies['XSRF-TOKEN']->isHttpOnly());
        $this->assertNull($sessionCookie->getDomain());
        $this->assertSame('/', $sessionCookie->getPath());
        $this->assertSame('lax', $sessionCookie->getSameSite());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_session_lock_timeout_is_unavailability_and_never_authorizes(): void
    {
        Route::get('/lock-failure', function (): never {
            throw new LockTimeoutException;
        });

        $this->getJson('/lock-failure')->assertStatus(503)
            ->assertJsonPath('error', 'AUTH_SERVICE_UNAVAILABLE');
    }

    public function test_a_persisted_session_authenticates_before_its_idle_deadline(): void
    {
        $sessionId = $this->createPersistedSession(now()->timestamp);

        $this->withCookie(config('session.cookie'), $sessionId)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/user')->assertOk();
    }

    public function test_a_persisted_session_is_rejected_after_its_configured_idle_deadline(): void
    {
        $sessionId = $this->createPersistedSession(now()->subMinutes(config('session.lifetime') + 1)->timestamp);

        $this->withCookie(config('session.cookie'), $sessionId)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/user')->assertUnauthorized();
    }

    public function test_a_storage_failure_returns_503_without_logging_session_bindings(): void
    {
        Log::spy();
        Route::get('/storage-failure', function (): never {
            throw new QueryException('mysql', 'insert into sessions values (?)',
                ['private-session-payload'], new RuntimeException('Storage unavailable.'));
        });

        $this->getJson('/storage-failure')->assertStatus(503)
            ->assertJsonPath('error', 'AUTH_SERVICE_UNAVAILABLE')
            ->assertDontSee('private-session-payload');

        Log::shouldHaveReceived('warning')->once()->with(
            'AuthService storage or session lock unavailable.',
            ['exception' => QueryException::class],
        );
        Log::shouldNotHaveReceived('error');
    }

    private function createPersistedSession(int $lastActivity): string
    {
        $this->withCredentials();
        config()->set('session.driver', 'database');
        $user = User::factory()->create();
        $sessionId = Str::random(40);
        $guardSessionKey = auth('web')->getName();
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'payload' => base64_encode(json_encode([
                $guardSessionKey => $user->id,
                '_token' => Str::random(40),
            ], JSON_THROW_ON_ERROR)),
            'last_activity' => $lastActivity,
        ]);

        return $sessionId;
    }
}
