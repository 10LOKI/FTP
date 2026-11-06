<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RolePermissionChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->actor = User::factory()->create();
        $this->grant($this->actor, ['ROLE_MANAGE', 'USER_ASSIGN_ROLE']);
        $this->role = Role::factory()->create();
        $this->role->permissions()->attach($this->permission('USER_CREATE')->id);
        $this->permission('USER_RESET_ACCESS');
        $this->signIn($this->actor, 'web');
    }

    public function test_confirmed_change_updates_access_version_and_audit_once(): void
    {
        $member = User::factory()->create();
        $this->role->users()->attach($member->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        $payload = $this->preview($this->role, ['USER_RESET_ACCESS']);
        $this->putJson($this->url($this->role), $payload)->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.permissions', ['USER_RESET_ACCESS']);
        $this->assertTrue(Gate::forUser($member)->allows('USER_RESET_ACCESS'));
        $this->assertFalse(Gate::forUser($member)->allows('USER_CREATE'));
        $event = AuditEvent::where('action', 'role.permissions_changed')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame(['permissions' => ['USER_CREATE'], 'version' => 1], $event->changes['before']);
        $this->assertSame(['permissions' => ['USER_RESET_ACCESS'], 'version' => 2], $event->changes['after']);
        $this->assertStringNotContainsString($payload['confirmation_token'], $event->toJson());
        $this->assertStringNotContainsString($this->actor->password, $event->toJson());
        $this->putJson($this->url($this->role), $payload)->assertConflict();
        $this->assertSame(1, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    #[DataProvider('missingAdministratorCapability')]
    public function test_last_administrator_cannot_lose_either_required_permission(array $proposed): void
    {
        $role = $this->actor->roles()->sole();
        $payload = $this->preview($role, $proposed);
        $this->putJson($this->url($role), $payload)->assertConflict();
        $this->assertTrue(Gate::forUser($this->actor)->allows('ROLE_MANAGE'));
        $this->assertTrue(Gate::forUser($this->actor)->allows('USER_ASSIGN_ROLE'));
        $this->assertSame(1, $role->fresh()->version);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    /** @return array<string, array{list<string>}> */
    public static function missingAdministratorCapability(): array
    {
        return ['remove management' => [['USER_ASSIGN_ROLE']], 'remove assignment' => [['ROLE_MANAGE']], 'remove both' => [[]]];
    }

    public function test_permissions_on_different_people_do_not_form_an_administrator(): void
    {
        $backup = User::factory()->create();
        $this->grant($backup, ['USER_ASSIGN_ROLE']);
        $role = $this->actor->roles()->sole();
        $this->putJson($this->url($role), $this->preview($role, ['ROLE_MANAGE']))->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_another_usable_administrator_allows_removing_own_management_access(): void
    {
        $backup = User::factory()->create();
        $this->grant($backup, ['ROLE_MANAGE', 'USER_ASSIGN_ROLE']);
        $role = $this->actor->roles()->sole();
        $this->putJson($this->url($role), $this->preview($role, ['USER_ASSIGN_ROLE']))->assertOk();
        $this->assertFalse(Gate::forUser($this->actor)->allows('ROLE_MANAGE'));
        $this->assertTrue(Gate::forUser($backup)->allows('ROLE_MANAGE'));
        $this->assertSame(1, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_administrator_permissions_can_be_combined_across_roles_on_the_same_person(): void
    {
        $this->grant($this->actor, ['ROLE_MANAGE']);
        $this->grant($this->actor, ['USER_ASSIGN_ROLE']);
        $role = $this->actor->roles()->whereHas('permissions', fn ($query) => $query->where('code', 'ROLE_MANAGE'))->orderBy('roles.id')->get()
            ->first(fn (Role $candidate): bool => $candidate->permissions()->count() === 2);
        $this->putJson($this->url($role), $this->preview($role, []))->assertOk();
        $this->assertTrue(Gate::forUser($this->actor)->allows('ROLE_MANAGE'));
        $this->assertTrue(Gate::forUser($this->actor)->allows('USER_ASSIGN_ROLE'));
    }

    public function test_temporary_backup_does_not_count_as_a_usable_administrator(): void
    {
        $backup = User::factory()->create(['must_change_password' => true]);
        $this->grant($backup, ['ROLE_MANAGE', 'USER_ASSIGN_ROLE']);
        $role = $this->actor->roles()->sole();
        $this->putJson($this->url($role), $this->preview($role, []))->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_unapproved_customer_backup_does_not_count_as_usable(): void
    {
        $backup = CustomerProfile::factory()->create(['approval' => 'EN_ATTENTE']);
        $this->grant($backup->user, ['ROLE_MANAGE', 'USER_ASSIGN_ROLE']);
        $role = $this->actor->roles()->sole();
        $this->putJson($this->url($role), $this->preview($role, []))->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_protected_permission_cannot_be_removed_or_added(): void
    {
        $permission = $this->permission('USER_CREATE');
        $permission->forceFill(['is_protected' => true])->save();
        $this->putJson($this->url($this->role), $this->preview($this->role, []))->assertConflict();
        $this->role->permissions()->detach($permission->id);
        $this->putJson($this->url($this->role), $this->preview($this->role, ['USER_CREATE']))->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_changed_proposal_or_membership_requires_a_new_preview(): void
    {
        $payload = $this->preview($this->role, ['USER_RESET_ACCESS']);
        $modified = $payload;
        $modified['permission_codes'] = [];
        $this->putJson($this->url($this->role), $modified)->assertConflict();
        $this->role->users()->attach($this->actor->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        $this->putJson($this->url($this->role), $payload)->assertConflict();
        $this->assertSame(1, $this->role->fresh()->version);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_missing_tampered_or_expired_confirmation_is_rejected(): void
    {
        $this->freezeTime();
        $payload = $this->preview($this->role, []);
        $missing = $payload;
        unset($missing['confirmation_token']);
        $this->putJson($this->url($this->role), $missing)->assertUnprocessable();
        $tampered = $payload;
        $tampered['confirmation_token'] = 'tampered';
        $this->putJson($this->url($this->role), $tampered)->assertUnprocessable();
        $this->travel(10)->minutes();
        $this->putJson($this->url($this->role), $payload)->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_an_unchanged_confirmation_is_a_no_op(): void
    {
        $payload = $this->preview($this->role, ['USER_CREATE']);
        $this->putJson($this->url($this->role), $payload)->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_revoked_actor_cannot_apply_an_existing_preview(): void
    {
        $payload = $this->preview($this->role, []);
        $this->actor->roles()->detach();
        $this->putJson($this->url($this->role), $payload)->assertForbidden();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_temporary_actor_cannot_apply_an_existing_preview(): void
    {
        $payload = $this->preview($this->role, []);
        $this->actor->forceFill(['must_change_password' => true])->save();
        $this->putJson($this->url($this->role), $payload)->assertForbidden();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_injected_fields_and_stale_versions_do_not_write(): void
    {
        $payload = $this->preview($this->role, []);
        $this->putJson($this->url($this->role), [...$payload, 'is_protected' => false])->assertUnprocessable();
        $this->putJson($this->url($this->role), [...$payload, 'version' => 2])->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_two_previews_cannot_remove_both_remaining_administrators(): void
    {
        $backup = User::factory()->create();
        $backupRole = $this->grant($backup, ['ROLE_MANAGE', 'USER_ASSIGN_ROLE']);
        $ownRole = $this->actor->roles()->sole();
        $ownPayload = $this->preview($ownRole, []);
        $this->signIn($backup, 'web');
        $backupPayload = $this->preview($backupRole, []);
        $this->signIn($this->actor, 'web');
        $this->putJson($this->url($ownRole), $ownPayload)->assertOk();
        $this->signIn($backup, 'web');
        $this->putJson($this->url($backupRole), $backupPayload)->assertConflict();
        $this->assertTrue(Gate::forUser($backup)->allows('ROLE_MANAGE'));
        $this->assertTrue(Gate::forUser($backup)->allows('USER_ASSIGN_ROLE'));
        $this->assertSame(1, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_another_authorized_actor_cannot_confirm_someone_elses_preview(): void
    {
        $payload = $this->preview($this->role, []);
        $other = User::factory()->create();
        $this->grant($other, ['ROLE_MANAGE', 'USER_ASSIGN_ROLE']);
        $this->signIn($other, 'web');
        $this->putJson($this->url($this->role), $payload)->assertForbidden();
        $this->assertSame(1, $this->role->fresh()->version);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_guest_cannot_confirm_an_existing_preview(): void
    {
        $payload = $this->preview($this->role, []);
        $this->app['auth']->forgetGuards();
        $this->putJson($this->url($this->role), $payload)->assertUnauthorized();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_role_protected_after_preview_cannot_be_changed(): void
    {
        $payload = $this->preview($this->role, []);
        $this->role->forceFill(['is_protected' => true])->save();
        $this->putJson($this->url($this->role), $payload)->assertConflict();
        $this->assertSame(['USER_CREATE'], $this->role->permissions()->pluck('code')->all());
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_failed_audit_rolls_back_permissions_and_version(): void
    {
        $payload = $this->preview($this->role, []);
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            $this->putJson($this->url($this->role), $payload);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertSame(['USER_CREATE'], $this->role->permissions()->pluck('code')->all());
        $this->assertSame(1, $this->role->fresh()->version);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    /** @param list<string> $proposed
     * @return array<string, mixed>
     */
    private function preview(Role $role, array $proposed): array
    {
        $response = $this->postJson('/api/v1/access/roles/'.$role->id.'/permission-change-preview', [
            'version' => $role->fresh()->version, 'permission_codes' => $proposed,
        ])->assertOk();

        return ['version' => $response->json('data.version'), 'permission_codes' => $proposed,
            'confirmation_token' => $response->json('data.confirmation.token')];
    }

    /** @param list<string> $codes */
    private function grant(User $user, array $codes): Role
    {
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        foreach ($codes as $code) {
            $role->permissions()->attach($this->permission($code)->id);
        }

        return $role;
    }

    private function signIn(User $user, string $guard = 'web'): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, $guard);
    }

    private function permission(string $code): Permission
    {
        return Permission::firstOrCreate(['code' => $code], ['module' => 'access', 'label' => $code]);
    }

    private function url(Role $role): string
    {
        return '/api/v1/access/roles/'.$role->id.'/permissions';
    }
}
