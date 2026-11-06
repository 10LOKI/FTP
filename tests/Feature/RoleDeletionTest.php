<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class RoleDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $access = Role::factory()->create();
        $permission = Permission::factory()->create(['code' => 'ROLE_DELETE']);
        $access->permissions()->attach($permission->id);
        $this->actor->roles()->attach($access->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        $this->role = Role::factory()->create();
        $this->actingAs($this->actor, 'web');
    }

    public function test_preview_is_read_only_and_deletion_preserves_permissions_accounts_and_history(): void
    {
        $permission = Permission::factory()->create();
        $this->role->permissions()->attach($permission->id);
        $this->getJson($this->url().'/deletion-preview')->assertOk()->assertJsonPath('data.can_delete', true)
            ->assertJsonPath('data.assigned_accounts', 0)->assertJsonPath('data.version', 1);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
        $this->deleteJson($this->url(), $this->payload())->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $this->role->id]);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
        $this->assertDatabaseHas('users', ['id' => $this->actor->id]);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $this->role->id]);
        $event = AuditEvent::where('action', 'role.deleted')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame($this->role->id, $event->object_id);
        $this->assertSame([$permission->code], $event->changes['before']['permissions']);
        $this->assertNull($event->changes['after']);
        $this->deleteJson($this->url(), $this->payload())->assertNotFound();
        $this->assertSame(1, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_protected_role_is_refused(): void
    {
        $this->role->forceFill(['is_protected' => true])->save();
        $this->getJson($this->url().'/deletion-preview')->assertOk()->assertJsonPath('data.can_delete', false);
        $this->deleteJson($this->url(), $this->payload())->assertConflict();
        $this->assertDatabaseHas('roles', ['id' => $this->role->id]);
    }

    public function test_any_assignment_including_temporary_accounts_blocks_deletion(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->role->users()->attach($user->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        $this->getJson($this->url().'/deletion-preview')->assertOk()->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.assigned_accounts', 1);
        $this->deleteJson($this->url(), $this->payload())->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
        $this->assertDatabaseHas('user_roles', ['role_id' => $this->role->id, 'user_id' => $user->id]);
    }

    public function test_assignment_after_preview_is_rechecked_at_deletion(): void
    {
        $this->getJson($this->url().'/deletion-preview')->assertOk()->assertJsonPath('data.can_delete', true);
        $this->role->users()->attach($this->actor->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        $this->deleteJson($this->url(), $this->payload())->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_protected_permission_links_are_preserved(): void
    {
        $permission = Permission::factory()->create(['is_protected' => true]);
        $this->role->permissions()->attach($permission->id);
        $this->getJson($this->url().'/deletion-preview')->assertOk()->assertJsonPath('data.has_protected_permissions', true);
        $this->deleteJson($this->url(), $this->payload())->assertConflict();
        $this->assertDatabaseHas('role_permissions', ['role_id' => $this->role->id, 'permission_id' => $permission->id]);
    }

    public function test_stale_version_missing_confirmation_and_injected_fields_are_refused(): void
    {
        $this->deleteJson($this->url(), ['version' => 1])->assertUnprocessable();
        $this->deleteJson($this->url(), ['version' => 1, 'confirmed' => false])->assertUnprocessable();
        $this->deleteJson($this->url(), [...$this->payload(), 'force' => true])->assertUnprocessable();
        $this->role->forceFill(['version' => 2])->save();
        $this->deleteJson($this->url(), $this->payload())->assertConflict();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_reassignment_then_fresh_request_allows_deletion(): void
    {
        $this->role->users()->attach($this->actor->id, ['assigned_by' => $this->actor->id, 'assigned_at' => now()]);
        $this->deleteJson($this->url(), $this->payload())->assertConflict();
        $this->role->users()->detach();
        $this->getJson($this->url().'/deletion-preview')->assertOk()->assertJsonPath('data.can_delete', true);
        $this->deleteJson($this->url(), $this->payload())->assertNoContent();
    }

    public function test_revocation_guests_and_temporary_actors_cannot_delete(): void
    {
        $this->actor->forceFill(['must_change_password' => true])->save();
        $this->deleteJson($this->url(), $this->payload())->assertForbidden();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actor->roles()->detach();
        $this->deleteJson($this->url(), $this->payload())->assertForbidden();
        $this->getJson($this->url().'/deletion-preview')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->deleteJson($this->url(), $this->payload())->assertUnauthorized();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_audit_failure_rolls_back_role_and_permission_links(): void
    {
        $permission = Permission::factory()->create();
        $this->role->permissions()->attach($permission->id);
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            $this->deleteJson($this->url(), $this->payload());
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseHas('roles', ['id' => $this->role->id]);
        $this->assertDatabaseHas('role_permissions', ['role_id' => $this->role->id, 'permission_id' => $permission->id]);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    /** @return array{version: int, confirmed: bool} */
    private function payload(): array
    {
        return ['version' => 1, 'confirmed' => true];
    }

    private function url(): string
    {
        return '/api/v1/access/roles/'.$this->role->id;
    }
}
