<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\EmployeeAccessDelivery;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeAccessSender;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EmployeeAccessResetTest extends TestCase
{
    use DatabaseMigrations;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'employee_access.validity_hours' => 24, 'employee_access.portal_url' => 'https://staff.example.com']);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor, 'web');
        Gate::define('USER_RESET_ACCESS', fn (User $user): bool => $user->is($this->actor));
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            DB::table('employee_access_deliveries')->delete();
        }
        parent::tearDown();
    }

    public function test_reset_invalidates_current_credentials_atomically_without_sending_or_changing_account(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        $hash = $user->password;
        $oldExpiry = $old->expires_at->toISOString();
        $this->travel(2)->hours();
        $request = $this->payload($user);
        $response = $this->reset($user, $request)->assertStatus(202)
            ->assertJsonPath('data.deliveryId', $request['request_id'])->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.mustChangePassword', true)->assertJsonPath('data.accessVersion', 2);
        $user->refresh();
        $this->assertNotSame($hash, $user->password);
        $this->assertFalse(Hash::check('previous-password', $user->password));
        $this->assertSame(2, $user->access_version);
        $this->assertTrue($user->must_change_password);
        $this->assertSame([$role->id], $user->roles()->pluck('roles.id')->all());
        $this->assertEqualsWithDelta(86400, now('UTC')->diffInSeconds($user->temporary_password_expires_at), 2);
        $this->assertSame($oldExpiry, $old->fresh()->expires_at->toISOString());
        $this->assertSame('EXPIRED', $old->fresh()->status);
        $this->assertSame('ACCESS_SUPERSEDED', $old->fresh()->last_error_code);
        $this->assertSame(0, Mail::mailer()->getSymfonyTransport()->messages()->count());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('employee_access_deliveries', 2);
        $audit = AuditEvent::where('action', 'employee.access.reset')->sole();
        $this->assertSame($this->actor->id, $audit->actor_id);
        $this->assertSame($request['request_id'], $audit->request_id);
        foreach ([$hash, $user->password, 'previous-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $audit->toJson().$response->getContent());
        }
    }

    public function test_repeated_request_is_idempotent_even_after_delivery_and_does_not_rotate_again(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $request = $this->payload($user);
        $this->reset($user, $request)->assertStatus(202);
        $this->assertTrue(app(EmployeeAccessSender::class)->send($request['request_id']));
        $hash = $user->fresh()->password;
        $version = $user->fresh()->access_version;
        $request['request_id'] = strtoupper($request['request_id']);
        $this->reset($user, $request)->assertStatus(202)->assertJsonPath('data.status', 'SENT');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($version, $user->fresh()->access_version);
        $this->assertDatabaseCount('employee_access_deliveries', 2);
        $this->assertSame(1, AuditEvent::where('action', 'employee.access.reset')->count());
        $this->assertSame(1, Mail::mailer()->getSymfonyTransport()->messages()->count());
    }

    public function test_stale_version_new_duplicate_click_and_request_id_reuse_are_conflicts(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $request = $this->payload($user);
        $this->reset($user, $request)->assertStatus(202);
        $this->reset($user, ['request_id' => (string) Str::uuid(), 'expected_access_version' => 1])->assertConflict();
        $this->reset($user, ['request_id' => $request['request_id'], 'expected_access_version' => 2])->assertConflict();
        $other = User::findOrFail($this->delivery()->user_id);
        $this->reset($other, $request)->assertConflict();
        $this->assertSame(1, $other->fresh()->access_version);
        $this->assertSame(1, AuditEvent::where('action', 'employee.access.reset')->count());
    }

    public function test_replay_by_another_authorized_actor_cannot_reuse_request_id(): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        $request = $this->payload($user);
        $this->reset($user, $request)->assertStatus(202);
        $other = User::factory()->create();
        Auth::forgetGuards();
        $this->actingAs($other, 'web');
        Gate::define('USER_RESET_ACCESS', fn (User $actor): bool => $actor->is($other));
        $this->reset($user, $request)->assertConflict();
    }

    public function test_guests_and_creators_without_reset_permission_cannot_reset(): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        Auth::forgetGuards();
        $this->reset($user, $this->payload($user))->assertUnauthorized();
        $other = User::factory()->create();
        Auth::forgetGuards();
        $this->actingAs($other, 'web');
        Gate::define('USER_CREATE', fn (User $actor): bool => true);
        Gate::define('USER_ASSIGN_ROLE', fn (User $actor): bool => true);
        $this->reset($user, $this->payload($user))->assertForbidden();
        $this->assertSame(1, $user->fresh()->access_version);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_or_injected_payload_never_rotates_access(array $override, string $field): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        $hash = $user->password;
        $this->reset($user, array_replace($this->payload($user), $override))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('employee_access_deliveries', 1);
    }

    public static function invalidPayloads(): array
    {
        return [
            [['request_id' => null], 'request_id'],
            [['request_id' => 'not-a-uuid'], 'request_id'],
            [['expected_access_version' => null], 'expected_access_version'],
            [['expected_access_version' => -1], 'expected_access_version'],
            [['expected_access_version' => 1.5], 'expected_access_version'],
            [['password' => 'admin-selected'], 'password'],
            [['temporary_password' => null], 'temporary_password'],
            [['email' => 'attacker@example.com'], 'email'],
            [['role_ids' => []], 'role_ids'],
            [['must_change_password' => false], 'must_change_password'],
        ];
    }

    public function test_public_unprovisioned_protected_and_self_accounts_are_not_reset_by_this_endpoint(): void
    {
        $ordinary = User::factory()->create()->refresh();
        $this->reset($ordinary, $this->payload($ordinary))->assertConflict();
        $protected = User::findOrFail($this->delivery()->user_id);
        $role = Role::factory()->create(['is_protected' => true]);
        $protected->roles()->attach($role->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        $this->reset($protected, $this->payload($protected))->assertConflict();
        $this->actor->access_version = 1;
        $this->actor->save();
        Auth::forgetGuards();
        $this->actingAs($this->actor, 'web')->withHeader('Origin', 'http://localhost:8090')->withSession(['employee_access_version' => 1]);
        $this->reset($this->actor, $this->payload($this->actor))->assertConflict();
        $this->assertSame(1, $protected->fresh()->access_version);
    }

    public function test_audit_failure_rolls_back_credentials_supersession_and_new_delivery(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $hash = $user->password;
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'employee.access.reset') {
                throw new RuntimeException('Simulated reset audit failure');
            }
        });
        $this->reset($user, $this->payload($user))->assertStatus(500);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(1, $user->fresh()->access_version);
        $this->assertSame('PENDING', $old->fresh()->status);
        $this->assertDatabaseCount('employee_access_deliveries', 1);
    }

    public function test_intent_failure_rolls_back_reset_and_old_delivery_state(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $hash = $user->password;
        EmployeeAccessDelivery::creating(function (): void {
            throw new RuntimeException('Simulated intent failure');
        });
        $this->reset($user, $this->payload($user))->assertStatus(500);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame('PENDING', $old->fresh()->status);
        $this->assertSame(0, AuditEvent::where('action', 'employee.access.reset')->count());
    }

    public function test_reissue_after_expiry_creates_new_deadline_and_old_worker_cannot_send(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $this->travel(25)->hours();
        $this->assertFalse(app(EmployeeAccessSender::class)->send($old->id));
        $this->assertSame('EXPIRED', $old->fresh()->status);
        $request = $this->payload($user);
        $this->reset($user, $request)->assertStatus(202);
        $hash = $user->fresh()->password;
        $this->assertFalse(app(EmployeeAccessSender::class)->send($old->id));
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue(app(EmployeeAccessSender::class)->send($request['request_id']));
        $this->assertSame(1, Mail::mailer()->getSymfonyTransport()->messages()->count());
    }

    public function test_explicit_reissue_replaces_sent_secret_and_old_initialization_session(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $this->assertTrue(app(EmployeeAccessSender::class)->send($old->id));
        $secret = $this->lastSecret();
        $oldVersion = $user->fresh()->access_version;
        $request = $this->payload($user->fresh());
        $this->reset($user, $request)->assertStatus(202);
        $this->assertFalse(Hash::check($secret, $user->fresh()->password));
        $this->assertSame('SENT', $old->fresh()->status);
        $this->assertFalse(app(EmployeeAccessSender::class)->send($old->id));
        $this->assertTrue(app(EmployeeAccessSender::class)->send($request['request_id']));
        $newSecret = $this->lastSecret();
        $this->assertNotSame($secret, $newSecret);
        $this->assertTrue(Hash::check($newSecret, $user->fresh()->password));
        Auth::forgetGuards();
        $this->actingAs($user->fresh(), 'web')->withSession(['employee_access_version' => $oldVersion]);
        $this->postJson('/api/v1/temporary-password', ['current_password' => $secret, 'password' => 'personal-new-password', 'password_confirmation' => 'personal-new-password'])
            ->assertUnauthorized()->assertJsonPath('code', 'SESSION_INVALIDATED');
        Auth::forgetGuards();
        $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => $secret])->assertUnauthorized();
        $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => $newSecret])->assertOk();
        $this->postJson('/api/v1/temporary-password', ['current_password' => $newSecret, 'password' => 'personal-new-password', 'password_confirmation' => 'personal-new-password'])->assertOk();
        $this->assertDatabaseCount('users', 2);
        $this->assertStringNotContainsString($newSecret, AuditEvent::all()->toJson().json_encode(DB::table('employee_access_deliveries')->get()));
    }

    public function test_reset_of_permanent_access_does_not_change_customer_approval_and_invalidates_business_session(): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        $user->must_change_password = false;
        $user->temporary_password_expires_at = null;
        $user->save();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'EN_ATTENTE', 'customer_code' => null]);
        $this->reset($user, $this->payload($user))->assertStatus(202);
        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
        $this->assertTrue($user->fresh()->must_change_password);
        Auth::forgetGuards();
        $this->actingAs($user->fresh(), 'web')->withSession(['employee_access_version' => 1]);
        $this->getJson('/api/v1/user')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->postJson('/api/v1/temporary-password', [])->assertUnauthorized();
    }

    public function test_failed_reset_delivery_is_retryable_and_uses_same_account_and_original_reset_expiry(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $request = $this->payload($user);
        $response = $this->reset($user, $request)->assertStatus(202);
        $expiry = $response->json('data.expiresAt');
        config(['mail.default' => 'log']);
        $this->assertFalse(app(EmployeeAccessSender::class)->send($request['request_id']));
        $this->getJson('/api/v1/employee-access-deliveries/'.$request['request_id'])->assertOk()
            ->assertJsonPath('data.status', 'RETRY')->assertJsonPath('data.lastErrorCode', 'ACCESS_DELIVERY_FAILED');
        config(['mail.default' => 'array']);
        $this->travel(5)->minutes();
        $this->artisan('employees:send-access')->assertSuccessful();
        $delivery = EmployeeAccessDelivery::findOrFail($request['request_id']);
        $this->assertSame('SENT', $delivery->status);
        $this->assertSame($expiry, $delivery->expires_at->toISOString());
        $this->assertSame(2, $delivery->attempts);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(1, Mail::mailer()->getSymfonyTransport()->messages()->count());
    }

    public function test_cancelled_delivery_is_not_sent_and_delivery_audit_failure_remains_retryable(): void
    {
        $delivery = $this->delivery();
        $manager = Mail::getFacadeRoot();
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andReturnNull();
        Mail::shouldReceive('to')->once()->andReturn($pending);
        $this->assertFalse(app(EmployeeAccessSender::class)->send($delivery->id));
        Mail::swap($manager);
        $this->assertSame('RETRY', $delivery->fresh()->status);
        $this->travel(5)->minutes();
        $hash = User::findOrFail($delivery->user_id)->password;
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'employee.access.delivery') {
                throw new RuntimeException('Simulated delivery audit failure');
            }
        });
        try {
            app(EmployeeAccessSender::class)->send($delivery->id);
            $this->fail('Expected delivery audit failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated delivery audit failure', $exception->getMessage());
        }
        $this->assertSame('RETRY', $delivery->fresh()->status);
        $this->assertSame($hash, User::findOrFail($delivery->user_id)->password);
    }

    public function test_invalid_configuration_does_not_revoke_existing_access(): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        $hash = $user->password;
        config(['employee_access.validity_hours' => 0]);
        $this->reset($user, $this->payload($user))->assertStatus(503);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('employee_access_deliveries', 1);
    }

    public function test_delivery_history_migration_reverses_without_losing_a_single_intent(): void
    {
        $delivery = $this->delivery();
        $migration = require database_path('migrations/2026_11_05_131112_allow_employee_access_delivery_history.php');
        $migration->down();
        $this->assertSame($delivery->id, DB::table('employee_access_deliveries')->sole()->id);
        $migration->up();
        $this->assertSame($delivery->id, DB::table('employee_access_deliveries')->sole()->id);
        $this->assertTrue(Schema::hasColumn('employee_access_deliveries', 'requested_access_version'));
    }

    public function test_migration_rollback_refuses_to_destroy_multiple_historical_intents(): void
    {
        $old = $this->delivery();
        $user = User::findOrFail($old->user_id);
        $this->reset($user, $this->payload($user))->assertStatus(202);
        $migration = require database_path('migrations/2026_11_05_131112_allow_employee_access_delivery_history.php');
        try {
            $migration->down();
            $this->fail('Rollback should preserve multi-delivery history');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('multiple deliveries', $exception->getMessage());
        }
        $this->assertDatabaseCount('employee_access_deliveries', 2);
        $this->assertTrue(Schema::hasColumn('employee_access_deliveries', 'requested_access_version'));
    }

    public function test_transport_exception_secret_is_excluded_from_captured_logs_and_storage(): void
    {
        $delivery = $this->delivery();
        $logger = new Logger('employee-test');
        $handler = new TestHandler;
        $logger->pushHandler($handler);
        Log::swap(new \Illuminate\Log\Logger($logger));
        $pending = Mockery::mock();
        $secret = null;
        $pending->shouldReceive('send')->once()->andReturnUsing(function ($mail) use (&$secret): void {
            $secret = $mail->content()->with['temporaryPassword'];
            throw new RuntimeException('Sensitive transport error:'.$secret);
        });
        Mail::shouldReceive('to')->once()->andReturn($pending);
        $this->assertFalse(app(EmployeeAccessSender::class)->send($delivery->id));
        $this->assertNotNull($secret);
        Log::info('Delivery status checked');
        $this->assertTrue($handler->hasInfoRecords());
        $records = array_map(fn ($record): array => $record->toArray(), $handler->getRecords());
        $this->assertStringNotContainsString($secret, json_encode($records).AuditEvent::all()->toJson().json_encode(DB::table('employee_access_deliveries')->get()));
    }

    public function test_stateful_reset_requires_csrf_and_accepts_valid_session_cookie_header(): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        $middlewareClass = config('sanctum.middleware.validate_csrf_token');
        $middleware = Mockery::mock($middlewareClass, [$this->app, $this->app['encrypter']])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('runningUnitTests')->andReturn(false);
        $this->app->instance($middlewareClass, $middleware);
        $this->withHeader('Origin', 'http://localhost:8090');
        $request = $this->payload($user);
        $this->reset($user, $request)->assertStatus(419);
        $csrf = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withCookie(config('session.cookie'), $csrf->getCookie(config('session.cookie'))->getValue())
            ->withHeader('X-XSRF-TOKEN', $csrf->getCookie('XSRF-TOKEN', false)->getValue());
        $this->reset($user, $request)->assertStatus(202);
    }

    public function test_reset_cannot_disable_the_last_usable_administrator(): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        $user->forceFill(['must_change_password' => false])->save();
        $this->grantAdministrativeAccess($user);
        $password = $user->password;
        $version = $user->access_version;
        $this->reset($user, $this->payload($user))->assertConflict();
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame($version, $user->fresh()->access_version);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertSame(0, AuditEvent::where('action', 'employee.access.reset')->count());
    }

    public function test_administrator_reset_is_allowed_when_a_usable_backup_remains(): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        $user->forceFill(['must_change_password' => false])->save();
        $this->grantAdministrativeAccess($user);
        $backup = User::factory()->create();
        $this->grantAdministrativeAccess($backup);
        $this->reset($user, $this->payload($user))->assertStatus(202);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertFalse($backup->fresh()->must_change_password);
    }

    private function grantAdministrativeAccess(User $user): void
    {
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        foreach (['ROLE_MANAGE', 'USER_ASSIGN_ROLE'] as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'access', 'label' => $code]);
            $role->permissions()->attach($permission->id);
        }
    }

    public function test_inactive_administrator_backup_cannot_justify_resetting_the_last_usable_admin(): void
    {
        $user = User::findOrFail($this->delivery()->user_id);
        $user->forceFill(['must_change_password' => false])->save();
        $this->grantAdministrativeAccess($user);
        $backup = User::factory()->create(['inactivity_suspended_at' => now('UTC'), 'inactivity_version' => 1]);
        $this->grantAdministrativeAccess($backup);
        $this->reset($user, $this->payload($user))->assertConflict();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    private function delivery(): EmployeeAccessDelivery
    {
        $user = User::factory()->create(['password' => 'previous-password']);
        $user->must_change_password = true;
        $user->temporary_password_expires_at = now('UTC')->addDay();
        $user->access_version = 1;
        $user->save();

        return EmployeeAccessDelivery::create(['user_id' => $user->id, 'created_by' => $this->actor->id, 'expires_at' => $user->temporary_password_expires_at]);
    }

    private function payload(User $user): array
    {
        return ['request_id' => (string) Str::uuid(), 'expected_access_version' => $user->access_version];
    }

    private function reset(User $user, array $data): TestResponse
    {
        return $this->postJson('/api/v1/users/'.$user->id.'/access-resets', $data);
    }

    private function lastSecret(): string
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        preg_match('/Mot de passe temporaire : ([a-f0-9]{48})/u', $messages->last()->getOriginalMessage()->getTextBody(), $match);
        $this->assertNotEmpty($match);

        return $match[1];
    }
}
