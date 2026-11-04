<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_customer_logs_in_by_code_and_the_session_id_rotates(): void
    {
        $profile = $this->customer();
        $this->withSession(['pre_login_marker' => 'retained']);
        $before = session()->getId();
        $this->withCookie(config('session.cookie'), $before);

        $response = $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'customer-password']);
        $response->assertOk()->assertJsonPath('data.customerId', $profile->id)->assertSessionHas('pre_login_marker', 'retained');
        $this->assertAuthenticatedAs($profile->user, 'web');
        $this->assertNotSame($before, $response->getCookie(config('session.cookie'))->getValue());
        $event = AuditEvent::where('action', 'customer.login')->sole();
        $this->assertSame('SUCCESS', $event->result);
        $this->assertSame($profile->user_id, $event->actor_id);
        $this->assertStringNotContainsString('customer-password', $event->toJson());
        $this->assertStringNotContainsString($profile->user->password, $event->toJson());
    }

    #[DataProvider('blockedStates')]
    public function test_blocked_customer_sees_status_only_after_a_correct_password(string $state, string $errorCode): void
    {
        $profile = $this->customer($state);
        $this->postJson('/api/v1/login', ['identifier' => $profile->user->email, 'password' => 'customer-password'])
            ->assertForbidden()->assertJsonPath('code', $errorCode);
        $this->assertGuest('web');
        $this->assertSame('REFUSED', AuditEvent::where('action', 'customer.login')->sole()->result);
    }

    public static function blockedStates(): array
    {
        return [['EN_ATTENTE', 'ACCOUNT_PENDING'], ['REFUSE', 'ACCOUNT_REFUSED']];
    }

    public function test_wrong_password_and_unknown_identifier_share_the_same_generic_error(): void
    {
        $profile = $this->customer('EN_ATTENTE');
        $expected = ['code' => 'INVALID_CREDENTIALS', 'message' => 'Identifiants invalides.'];
        $this->postJson('/api/v1/login', ['identifier' => $profile->user->email, 'password' => 'wrong-password'])
            ->assertUnauthorized()->assertExactJson($expected);
        $this->postJson('/api/v1/login', ['identifier' => 'unknown@example.com', 'password' => 'wrong-password'])
            ->assertUnauthorized()->assertExactJson($expected);
        $this->assertGuest('web');
    }

    public function test_approved_customer_must_use_code_instead_of_email(): void
    {
        $profile = $this->customer();
        $this->postJson('/api/v1/login', ['identifier' => $profile->user->email, 'password' => 'customer-password'])
            ->assertForbidden()->assertJsonPath('code', 'CUSTOMER_CODE_REQUIRED');
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', ['identifier' => 'UNKNOWN', 'password' => 'wrong-password'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/login', ['identifier' => 'UNKNOWN', 'password' => 'wrong-password'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGuest('web');
    }

    public function test_login_rejects_missing_csrf_when_protection_is_enabled(): void
    {
        $this->enforceCsrf();
        $profile = $this->customer();
        $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'customer-password'])->assertStatus(419);
        $this->assertGuest('web');
    }

    public function test_login_accepts_the_csrf_cookie_header_and_session_cookie(): void
    {
        $this->enforceCsrf();
        $profile = $this->customer();
        $csrf = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withCookie(config('session.cookie'), $csrf->getCookie(config('session.cookie'))->getValue())
            ->withHeader('X-XSRF-TOKEN', $csrf->getCookie('XSRF-TOKEN', false)->getValue())
            ->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'customer-password'])->assertOk();
        $this->assertAuthenticatedAs($profile->user, 'web');
    }

    public function test_login_audit_failure_does_not_grant_a_session(): void
    {
        $profile = $this->customer();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'customer.login' && $event->result === 'SUCCESS') {
                throw new RuntimeException('Simulated audit storage failure');
            }
        });
        $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'customer-password'])->assertStatus(500);
        $this->assertGuest('web');
    }

    public function test_existing_session_cannot_be_switched_silently_by_login(): void
    {
        $first = $this->customer();
        $second = $this->customer(code: 'CLI-000002');
        $this->actingAs($first->user, 'web');
        $this->postJson('/api/v1/login', ['identifier' => $second->customer_code, 'password' => 'customer-password'])->assertConflict();
        $this->assertAuthenticatedAs($first->user, 'web');
    }

    public function test_logout_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        $profile = $this->customer();
        $login = $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'customer-password'])->assertOk();
        $cookie = $login->getCookie(config('session.cookie'))->getValue();
        $csrfToken = session()->token();
        $this->withCookie(config('session.cookie'), $cookie);
        Auth::forgetGuards();
        $logout = $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertNotSame($cookie, $logout->getCookie(config('session.cookie'))->getValue());
        $this->assertNotSame($csrfToken, session()->token());
        $this->withCookie(config('session.cookie'), $logout->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
        $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/user')->assertUnauthorized();
        $this->withCookie(config('session.cookie'), $cookie);
        Auth::forgetGuards();
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_blocked_session_can_still_log_out(): void
    {
        $profile = $this->customer('REFUSE');
        $this->actingAs($profile->user, 'web');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_logout_requires_csrf_even_with_a_valid_session(): void
    {
        $profile = $this->customer();
        $login = $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'customer-password'])->assertOk();
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
        $this->enforceCsrf();

        $this->postJson('/api/v1/logout')->assertStatus(419);
        $this->assertSame(0, AuditEvent::where('action', 'account.logout')->count());
    }

    public function test_employee_email_cannot_open_a_customer_session(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        $this->postJson('/api/v1/login', ['identifier' => $user->email, 'password' => 'customer-password'])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->assertGuest('web');
    }

    public function test_logout_audit_failure_still_invalidates_the_session(): void
    {
        $profile = $this->customer();
        $login = $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'customer-password'])->assertOk();
        $cookie = $login->getCookie(config('session.cookie'))->getValue();
        $this->withCookie(config('session.cookie'), $cookie);
        Auth::forgetGuards();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'account.logout') {
                throw new RuntimeException('Simulated logout audit failure');
            }
        });

        $this->postJson('/api/v1/logout')->assertStatus(500);
        $this->assertGuest('web');
        $this->withCookie(config('session.cookie'), $cookie);
        Auth::forgetGuards();
        $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_login_hook_failure_cannot_leave_an_authenticated_session_or_success_audit(): void
    {
        $profile = $this->customer();
        Event::listen(Login::class, function (): void {
            throw new RuntimeException('Simulated login listener failure');
        });

        $response = $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'customer-password'])->assertStatus(500);
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
        $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/user')->assertUnauthorized();
        $this->assertSame(0, AuditEvent::where('action', 'customer.login')->where('result', 'SUCCESS')->count());
    }

    public function test_login_does_not_ignore_password_bytes_beyond_the_bcrypt_limit(): void
    {
        $user = User::factory()->create(['password' => str_repeat('a', 72)]);
        $profile = CustomerProfile::factory()->create([
            'user_id' => $user->id, 'approval' => 'APPROUVE', 'customer_code' => 'BOUNDARY-001',
        ]);

        $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => str_repeat('a', 72).'b'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertGuest('web');
        $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => str_repeat('a', 72)])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    private function customer(string $state = 'APPROUVE', string $code = '000-ABC/12'): CustomerProfile
    {
        $user = User::factory()->create(['password' => 'customer-password']);

        return CustomerProfile::factory()->create([
            'user_id' => $user->id, 'approval' => $state,
            'customer_code' => $state === 'APPROUVE' ? $code : null,
        ]);
    }

    private function enforceCsrf(): void
    {
        $middleware = Mockery::mock(PreventRequestForgery::class, [$this->app, $this->app['encrypter']])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('runningUnitTests')->andReturn(false);
        $this->app->instance(PreventRequestForgery::class, $middleware);
    }
}
