<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LoginActivityTest extends TestCase
{
    use DatabaseMigrations;

    public function test_successful_customer_login_records_private_activity(): void
    {
        $profile = $this->customer();
        $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'login-password'])->assertOk();
        $this->assertNotNull($profile->user->fresh()->last_login_at);
        $this->assertSame(now('UTC')->toDateString(), $profile->user->fresh()->last_login_at->toDateString());
        $this->assertArrayNotHasKey('last_login_at', $profile->user->fresh()->toArray());
    }

    public function test_failed_password_and_unapproved_customer_do_not_advance_activity(): void
    {
        $profile = $this->customer();
        $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'wrong-password'])->assertUnauthorized();
        $profile->forceFill(['approval' => 'EN_ATTENTE'])->save();
        $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'login-password'])->assertForbidden();
        $this->assertNull($profile->user->fresh()->last_login_at);
    }

    public function test_successful_employee_login_records_activity_even_for_a_restricted_initial_session(): void
    {
        $user = User::factory()->create(['password' => 'login-password', 'access_version' => 1,
            'must_change_password' => true, 'temporary_password_expires_at' => now('UTC')->addDay()]);
        $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => 'login-password'])->assertOk();
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_failed_or_expired_employee_access_does_not_record_successful_activity(): void
    {
        $user = User::factory()->create(['password' => 'login-password', 'access_version' => 1,
            'must_change_password' => true, 'temporary_password_expires_at' => now('UTC')->subMinute()]);
        $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnauthorized();
        $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => 'login-password'])->assertForbidden();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_a_clock_rollback_does_not_overwrite_a_newer_activity_timestamp(): void
    {
        $profile = $this->customer();
        $future = now('UTC')->addDay()->startOfSecond();
        $profile->user->forceFill(['last_login_at' => $future])->save();
        $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'login-password'])->assertOk();
        $this->assertSame($future->getTimestamp(), $profile->user->fresh()->last_login_at->getTimestamp());
    }

    public function test_customer_audit_failure_rolls_back_activity_and_closes_session(): void
    {
        $profile = $this->customer();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'customer.login' && $event->result === 'SUCCESS') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->postJson('/api/v1/login', ['identifier' => $profile->customer_code, 'password' => 'login-password']);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertNull($profile->user->fresh()->last_login_at);
        $this->assertGuest('web');
    }

    public function test_migration_recovers_only_latest_successful_login_audit_records(): void
    {
        $migration = require database_path('migrations/2026_11_06_174155_add_last_login_at_to_users_table.php');
        $migration->down();
        $customer = User::factory()->create();
        $employee = User::factory()->create();
        $unknown = User::factory()->create();
        $this->event($customer, 'customer.login', 'SUCCESS', '2026-10-01 10:00:00');
        $this->event($customer, 'customer.login', 'SUCCESS', '2026-11-01 10:00:00');
        $this->event($customer, 'customer.login', 'REFUSED', '2026-11-05 10:00:00');
        $this->event($customer, 'application.request', 'SUCCESS', '2026-11-06 10:00:00');
        $this->event($employee, 'employee.login', 'SUCCESS', '2026-11-02 10:00:00');
        $migration->up();
        $this->assertSame('2026-11-01 10:00:00', $customer->fresh()->last_login_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 10:00:00', $employee->fresh()->last_login_at->format('Y-m-d H:i:s'));
        $this->assertNull($unknown->fresh()->last_login_at);
        $this->assertDatabaseCount('audit_events', 5);
    }

    private function customer(): CustomerProfile
    {
        $user = User::factory()->create(['password' => 'login-password']);

        return CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE', 'customer_code' => 'CLI-ACTIVITY']);
    }

    private function event(User $user, string $action, string $result, string $at): void
    {
        AuditEvent::create(['actor_id' => $user->id, 'request_id' => (string) Str::uuid(), 'action' => $action,
            'object_type' => 'user', 'object_id' => (string) $user->id, 'result' => $result,
            'occurred_at' => CarbonImmutable::parse($at, 'UTC')]);
    }
}
