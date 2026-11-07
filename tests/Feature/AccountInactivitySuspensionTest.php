<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountInactivityPolicy;
use App\Services\AdministratorAccess;
use App\Services\InactivitySuspension;
use App\Services\RolePermissionChange;
use App\Services\RolePermissionConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountInactivitySuspensionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
    }

    public function test_due_customer_and_employee_are_suspended_once_without_clearing_approval(): void
    {
        $employee = $this->due();
        $customer = $this->due();
        $profile = CustomerProfile::factory()->create(['user_id' => $customer->id, 'approval' => 'APPROUVE']);
        $counts = app(InactivitySuspension::class)->run();
        $this->assertSame(2, $counts['suspended']);
        $this->assertNotNull($employee->fresh()->inactivity_suspended_at);
        $this->assertNotNull($customer->fresh()->inactivity_suspended_at);
        $this->assertSame('APPROUVE', $profile->fresh()->approval);
        $this->assertSame(1, $employee->fresh()->inactivity_version);
        $this->assertSame(0, app(InactivitySuspension::class)->run()['suspended']);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_exact_deadline_is_enforced_and_before_deadline_is_not(): void
    {
        $user = $this->due();
        $deadline = app(AccountInactivityPolicy::class)->assess($user)['deadline'];
        $this->travelTo($deadline->subSecond());
        $this->assertSame(0, app(InactivitySuspension::class)->run()['suspended']);
        $this->travelTo($deadline);
        $this->assertSame(1, app(InactivitySuspension::class)->run()['suspended']);
    }

    public function test_missing_approval_and_unapproved_customers_are_not_suspended(): void
    {
        $missing = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'decided_at' => null]);
        $pending = CustomerProfile::factory()->create(['approval' => 'EN_ATTENTE']);
        $counts = app(InactivitySuspension::class)->run();
        $this->assertSame(1, $counts['missing']);
        $this->assertSame(0, $counts['suspended']);
        $this->assertNull($missing->user->fresh()->inactivity_suspended_at);
        $this->assertNull($pending->user->fresh()->inactivity_suspended_at);
    }

    public function test_two_due_administrators_cannot_both_be_suspended(): void
    {
        $first = $this->due();
        $second = $this->due();
        $this->administrator($first);
        $this->administrator($second);
        $counts = app(InactivitySuspension::class)->run();
        $this->assertSame(1, $counts['suspended']);
        $this->assertSame(1, $counts['protected']);
        $this->assertSame(1, User::get()->filter(fn (User $user): bool => app(AdministratorAccess::class)->isUsable($user))->count());
    }

    public function test_temporary_and_already_suspended_backups_do_not_count_as_usable_admins(): void
    {
        $active = $this->due();
        $this->administrator($active);
        $temporary = User::factory()->create(['must_change_password' => true]);
        $this->administrator($temporary);
        $suspended = User::factory()->create(['inactivity_suspended_at' => now('UTC'), 'inactivity_version' => 1]);
        $this->administrator($suspended);
        $this->assertSame(1, app(InactivitySuspension::class)->run()['protected']);
        $this->assertNull($active->fresh()->inactivity_suspended_at);
    }

    public function test_suspended_backup_cannot_justify_removing_the_last_active_admin_permissions(): void
    {
        $active = User::factory()->create();
        $role = $this->administrator($active);
        $backup = User::factory()->create(['inactivity_suspended_at' => now('UTC'), 'inactivity_version' => 1]);
        $this->administrator($backup);
        $token = app(RolePermissionConfirmation::class)->issue($active, $role, [])['token'];
        try {
            app(RolePermissionChange::class)->apply($active, $role, 1, [], $token);
            $this->fail('Expected last-admin refusal.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertTrue(app(AdministratorAccess::class)->isUsable($active->fresh()));
        $this->assertSame(2, $role->permissions()->count());
    }

    public function test_dry_run_does_not_suspend_and_actual_command_is_idempotent(): void
    {
        $user = $this->due();
        $this->artisan('accounts:suspend-inactive', ['--dry-run' => true])->assertExitCode(0);
        $this->assertNull($user->fresh()->inactivity_suspended_at);
        $this->assertDatabaseCount('audit_events', 0);
        $this->artisan('accounts:suspend-inactive')->assertExitCode(0);
        $this->artisan('accounts:suspend-inactive')->assertExitCode(0);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_audit_failure_rolls_back_the_entire_suspension_batch(): void
    {
        $first = $this->due();
        $second = $this->due();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event) use ($second): void {
            if ($event->action === 'account.inactivity.suspended' && $event->object_id === (string) $second->id) {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(InactivitySuspension::class)->run();
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertNull($first->fresh()->inactivity_suspended_at);
        $this->assertNull($second->fresh()->inactivity_suspended_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function due(): User
    {
        return User::factory()->create(['last_login_at' => '2026-01-01 12:00:00']);
    }

    private function administrator(User $user): Role
    {
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        foreach (['ROLE_MANAGE', 'USER_ASSIGN_ROLE'] as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'access', 'label' => $code]);
            $role->permissions()->attach($permission->id);
        }

        return $role;
    }
}
