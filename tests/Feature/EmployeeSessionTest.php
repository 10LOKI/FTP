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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EmployeeSessionTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY = 'temporary-employee-secret';

    private const PERMANENT = 'my-personal-password-2026';

    public function test_first_login_rotates_session_and_exposes_only_safe_initialization_state(): void
    {
        $user = $this->employee();
        $this->withSession(['marker' => 'retained']);
        $before = session()->getId();
        $this->withCookie(config('session.cookie'), $before);
        $response = $this->login($user)->assertOk()->assertExactJson([
            'data' => ['userId' => $user->id, 'mustChangePassword' => true],
        ])->assertSessionHas('employee_access_version', 1);
        $this->assertNotSame($before, $response->getCookie(config('session.cookie'))->getValue());
        $this->assertAuthenticatedAs($user, 'web');
        $audit = AuditEvent::where('action', 'employee.login')->sole();
        $this->assertSame('restricted_session_opened', $audit->reason);
        $this->assertStringNotContainsString(self::TEMPORARY, $audit->toJson());
        $this->assertStringNotContainsString($user->password, $audit->toJson());
    }

    public function test_initial_session_can_only_replace_password_or_logout_even_with_business_permissions(): void
    {
        $user = $this->employee();
        Gate::define('USER_ASSIGN_ROLE', fn (User $actor): bool => $actor->is($user));
        $this->login($user)->assertOk();
        foreach (['/api/v1/user', '/api/v1/roles', '/api/v1/products/1', '/'] as $path) {
            $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        }
        $this->postJson('/api/v1/customer-registrations', [])->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_unknown_wrong_password_and_unprovisioned_accounts_share_generic_denial(): void
    {
        $user = $this->employee();
        $ordinary = User::factory()->create(['password' => self::TEMPORARY]);
        foreach ([[$user->email, 'wrong'], ['unknown@example.com', self::TEMPORARY], [$ordinary->email, self::TEMPORARY]] as [$email, $password]) {
            $this->postJson('/api/v1/employee-login', compact('email', 'password'))->assertUnauthorized()
                ->assertExactJson(['code' => 'INVALID_CREDENTIALS', 'message' => 'Identifiants invalides.']);
            $this->assertGuest('web');
        }
    }

    public function test_expiry_is_inclusive_and_does_not_disclose_state_for_wrong_password(): void
    {
        $user = $this->employee();
        $user->temporary_password_expires_at = now('UTC');
        $user->save();
        $this->login($user, 'wrong')->assertUnauthorized();
        $this->login($user)->assertForbidden()->assertJsonPath('code', 'TEMPORARY_ACCESS_EXPIRED');
        $this->assertGuest('web');
    }

    public function test_expiry_is_rechecked_after_login_before_replacement(): void
    {
        $user = $this->employee();
        $this->login($user)->assertOk();
        $this->travel(25)->hours();
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertForbidden()->assertJsonPath('code', 'TEMPORARY_ACCESS_EXPIRED');
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::TEMPORARY, $user->fresh()->password));
        $this->postJson('/api/v1/logout')->assertNoContent();
    }

    public function test_replacement_invalidates_initial_access_and_requires_fresh_login_without_granting_permissions(): void
    {
        $user = $this->employee();
        $this->login($user)->assertOk();
        $oldSession = session()->getId();
        $oldToken = session()->token();
        $response = $this->postJson('/api/v1/temporary-password', $this->replacement())->assertOk()
            ->assertJsonPath('code', 'PASSWORD_UPDATED_LOGIN_REQUIRED')->assertJsonPath('data.requiresLogin', true);
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->temporary_password_expires_at);
        $this->assertSame(2, $user->access_version);
        $this->assertTrue(Hash::check(self::PERMANENT, $user->password));
        $this->assertFalse(Hash::check(self::TEMPORARY, $user->password));
        $this->assertNotSame($oldSession, $response->getCookie(config('session.cookie'))->getValue());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertGuest('web');
        $audit = AuditEvent::where('action', 'employee.password.replaced')->sole();
        foreach ([self::TEMPORARY, self::PERMANENT, $user->password] as $secret) {
            $this->assertStringNotContainsString($secret, $audit->toJson().$response->getContent());
        }
        Auth::forgetGuards();
        $this->login($user)->assertUnauthorized();
        $this->login($user, self::PERMANENT)->assertOk()->assertJsonPath('data.mustChangePassword', false);
        $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_other_initialization_sessions_are_invalidated_after_replacement(): void
    {
        $user = $this->employee();
        $this->login($user)->assertOk();
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertOk();
        $this->actingAs($user->fresh(), 'web')->withSession(['employee_access_version' => 1]);
        $this->getJson('/api/v1/user')->assertUnauthorized()->assertJsonPath('code', 'SESSION_INVALIDATED');
        $this->assertGuest('web');
    }

    public function test_reissued_access_version_invalidates_an_existing_initialization_session(): void
    {
        $user = $this->employee();
        $this->login($user)->assertOk();
        $user->access_version++;
        $user->save();
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertUnauthorized();
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame(0, AuditEvent::where('action', 'employee.password.replaced')->count());
    }

    #[DataProvider('invalidReplacements')]
    public function test_invalid_replacement_preserves_credentials_and_restriction(array $override, string $field): void
    {
        $user = $this->employee();
        $hash = $user->password;
        $this->login($user)->assertOk();
        $this->postJson('/api/v1/temporary-password', array_replace($this->replacement(), $override))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame(1, $user->fresh()->access_version);
        $this->assertSame(0, AuditEvent::where('action', 'employee.password.replaced')->count());
    }

    public static function invalidReplacements(): array
    {
        return [
            'wrong current' => [['current_password' => 'wrong'], 'current_password'],
            'same secret' => [['password' => self::TEMPORARY, 'password_confirmation' => self::TEMPORARY], 'password'],
            'short' => [['password' => 'tiny', 'password_confirmation' => 'tiny'], 'password'],
            'unconfirmed' => [['password_confirmation' => 'different'], 'password'],
            'bcrypt overflow' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
            'null byte' => [['password' => "long-enough\0secret", 'password_confirmation' => "long-enough\0secret"], 'password'],
            'state injection' => [['must_change_password' => false], 'must_change_password'],
            'account injection' => [['user_id' => 123], 'user_id'],
        ];
    }

    public function test_guest_cannot_replace_and_normal_employee_cannot_replay_initialization(): void
    {
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertUnauthorized();
        $user = $this->employee();
        $user->must_change_password = false;
        $user->temporary_password_expires_at = null;
        $user->save();
        $this->login($user)->assertOk();
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertConflict();
    }

    public function test_password_audit_failure_rolls_back_hash_flag_expiry_and_version(): void
    {
        $user = $this->employee();
        $hash = $user->password;
        $expiry = $user->temporary_password_expires_at->toISOString();
        $this->login($user)->assertOk();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'employee.password.replaced') {
                throw new RuntimeException('Simulated audit failure');
            }
        });
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertStatus(500);
        $user->refresh();
        $this->assertSame($hash, $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertSame(1, $user->access_version);
        $this->assertSame($expiry, $user->temporary_password_expires_at->toISOString());
        $this->getJson('/api/v1/user')->assertForbidden();
    }

    public function test_login_audit_failure_does_not_leave_authenticated_session(): void
    {
        $user = $this->employee();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'employee.login' && $event->result === 'SUCCESS') {
                throw new RuntimeException('Simulated audit failure');
            }
        });
        $this->login($user)->assertStatus(500);
        $this->assertGuest('web');
    }

    public function test_login_listener_failure_also_closes_session(): void
    {
        $user = $this->employee();
        Event::listen(Login::class, function (): void {
            throw new RuntimeException('Simulated session hook failure');
        });
        $this->login($user)->assertStatus(500);
        $this->assertGuest('web');
    }

    public function test_customer_approval_is_independent_of_password_replacement(): void
    {
        $user = $this->employee();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'EN_ATTENTE', 'customer_code' => null]);
        $this->login($user)->assertOk();
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertOk();
        Auth::forgetGuards();
        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
        $this->login($user, self::PERMANENT)->assertForbidden()->assertJsonPath('code', 'CUSTOMER_CODE_REQUIRED');
        $this->postJson('/api/v1/login', ['identifier' => $user->email, 'password' => self::PERMANENT])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING');
        $this->assertGuest('web');
    }

    public function test_customer_login_cannot_bypass_mandatory_change_and_code_login_works_afterward(): void
    {
        $user = $this->employee();
        CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE', 'customer_code' => 'CLI-INIT']);
        $this->postJson('/api/v1/login', ['identifier' => 'CLI-INIT', 'password' => self::TEMPORARY])
            ->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->assertGuest('web');
        $this->login($user)->assertOk();
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertOk();
        Auth::forgetGuards();
        $this->postJson('/api/v1/login', ['identifier' => 'CLI-INIT', 'password' => self::PERMANENT])->assertOk();
        $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/customer-account')->assertOk();
    }

    public function test_login_and_password_change_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/employee-login', ['email' => 'absent@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/employee-login', ['email' => 'absent@example.com', 'password' => 'wrong'])->assertStatus(429);
        $user = $this->employee();
        $this->login($user)->assertOk();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/temporary-password', $this->replacement('wrong'))->assertUnprocessable();
        }
        $this->postJson('/api/v1/temporary-password', $this->replacement('wrong'))->assertStatus(429);
    }

    public function test_employee_login_rejects_extra_fields_and_preserves_existing_sessions(): void
    {
        $user = $this->employee();
        $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => self::TEMPORARY, 'remember' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('remember');
        $ordinary = User::factory()->create();
        $this->actingAs($ordinary, 'web');
        $this->login($user)->assertConflict();
        $this->assertAuthenticatedAs($ordinary, 'web');
    }

    public function test_login_and_replacement_require_csrf_and_accept_valid_cookie_headers(): void
    {
        $user = $this->employee();
        $this->enforceCsrf();
        $this->login($user)->assertStatus(419);
        $csrf = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withCookie(config('session.cookie'), $csrf->getCookie(config('session.cookie'))->getValue())
            ->withHeader('X-XSRF-TOKEN', $csrf->getCookie('XSRF-TOKEN', false)->getValue());
        $login = $this->login($user)->assertOk();
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
        $this->withoutHeader('X-XSRF-TOKEN');
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertStatus(419);
        $this->withHeader('X-XSRF-TOKEN', $login->getCookie('XSRF-TOKEN', false)->getValue());
        $this->postJson('/api/v1/temporary-password', $this->replacement())->assertOk();
    }

    public function test_a_password_that_looks_like_a_hash_is_still_treated_as_the_users_literal_password(): void
    {
        $user = $this->employee();
        $chosenPassword = Hash::make('underlying-password-must-not-work');
        $this->login($user)->assertOk();
        $this->postJson('/api/v1/temporary-password', [
            'current_password' => self::TEMPORARY, 'password' => $chosenPassword, 'password_confirmation' => $chosenPassword,
        ])->assertOk();
        $stored = $user->fresh()->password;
        $this->assertNotSame($chosenPassword, $stored);
        $this->assertTrue(Hash::check($chosenPassword, $stored));
        $this->assertFalse(Hash::check('underlying-password-must-not-work', $stored));
        Auth::forgetGuards();
        $this->login($user, 'underlying-password-must-not-work')->assertUnauthorized();
        $this->login($user, $chosenPassword)->assertOk();
    }

    private function employee(): User
    {
        $user = User::factory()->create(['password' => self::TEMPORARY]);
        $user->must_change_password = true;
        $user->temporary_password_expires_at = now('UTC')->addDay();
        $user->access_version = 1;
        $user->save();

        return $user;
    }

    private function login(User $user, string $password = self::TEMPORARY): TestResponse
    {
        return $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => $password]);
    }

    private function replacement(string $current = self::TEMPORARY): array
    {
        return ['current_password' => $current, 'password' => self::PERMANENT, 'password_confirmation' => self::PERMANENT];
    }

    private function enforceCsrf(): void
    {
        $middleware = Mockery::mock(PreventRequestForgery::class, [$this->app, $this->app['encrypter']])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('runningUnitTests')->andReturn(false);
        $this->app->instance(PreventRequestForgery::class, $middleware);
    }
}
