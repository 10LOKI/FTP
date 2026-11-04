<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SanctumAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_the_authenticated_user(): void
    {
        $this->withHeader('Origin', 'http://localhost:8090')
            ->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_web_session_authenticates_without_a_bearer_token(): void
    {
        $user = User::factory()->create();

        $this->withSession([Auth::guard('web')->getName() => $user->id])
            ->withHeader('Origin', 'http://localhost:8090')
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]]);
    }

    public function test_user_response_does_not_serialize_internal_fields(): void
    {
        $user = User::factory()->create();
        $user->setAttribute('internal_notes', 'Private staff information');
        $user->setAttribute('approval', 'EN_ATTENTE');

        $this->actingAs($user, 'web')->getJson('/api/v1/user')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]]);
    }

    public function test_stateful_api_rejects_writes_without_a_csrf_token(): void
    {
        $middlewareClass = config('sanctum.middleware.validate_csrf_token');
        $middleware = \Mockery::mock($middlewareClass, [$this->app, $this->app['encrypter']])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('runningUnitTests')->andReturn(false);
        $this->app->instance($middlewareClass, $middleware);

        Route::middleware('api')->post('/api/csrf-test', fn () => response()->noContent());

        $this->withHeader('Origin', 'http://localhost:8090')
            ->postJson('/api/csrf-test')->assertStatus(419);
    }

    public function test_csrf_cookie_can_be_initialized(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN')
            ->assertCookie(config('session.cookie'));
    }
}
