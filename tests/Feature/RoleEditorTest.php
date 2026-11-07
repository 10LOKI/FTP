<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RoleEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_guests_and_accounts_without_management_permission_cannot_write_or_preview(): void
    {
        $role = Role::factory()->create();
        $this->postJson('/api/v1/access/roles', ['code' => 'WAREHOUSE', 'label' => 'Warehouse'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web');
        $this->postJson('/api/v1/access/roles', ['code' => 'WAREHOUSE', 'label' => 'Warehouse'])->assertForbidden();
        $this->patchJson($this->url($role), ['label' => 'Changed', 'version' => 1])->assertForbidden();
        $this->postJson($this->url($role).'/permission-change-preview', ['version' => 1, 'permission_codes' => []])->assertForbidden();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_creation_makes_an_empty_ordinary_role_and_records_safe_audit(): void
    {
        $actor = $this->manager();
        $response = $this->postJson('/api/v1/access/roles', ['code' => 'WAREHOUSE', 'label' => 'Warehouse'])->assertCreated()
            ->assertJsonPath('data.code', 'WAREHOUSE')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.is_protected', false)->assertJsonPath('data.permissions', []);
        $role = Role::findOrFail($response->json('data.id'));
        $this->assertSame(0, $role->users()->count());
        $event = AuditEvent::where('action', 'role.created')->sole();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($role->id, $event->object_id);
        $this->assertSame(['before' => null, 'after' => ['code' => 'WAREHOUSE', 'label' => 'Warehouse', 'version' => 1]], $event->changes);
        $this->assertStringNotContainsString($actor->email, $event->toJson());
        $this->assertStringNotContainsString($actor->password, $event->toJson());
    }

    public function test_creation_rejects_duplicate_codes_and_injected_privileges(): void
    {
        $this->manager();
        Role::factory()->create(['code' => 'WAREHOUSE']);
        $this->postJson('/api/v1/access/roles', ['code' => 'WAREHOUSE', 'label' => 'Duplicate'])->assertUnprocessable();
        foreach (['is_protected' => true, 'version' => 99, 'permission_codes' => ['ROLE_MANAGE'], 'user_ids' => [1]] as $field => $value) {
            $this->postJson('/api/v1/access/roles', ['code' => 'NEW_ROLE', 'label' => 'New', $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/api/v1/access/roles', ['code' => 'bad code', 'label' => ''])->assertUnprocessable();
        $this->assertDatabaseMissing('roles', ['code' => 'NEW_ROLE']);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_rename_preserves_code_assignments_and_effective_access(): void
    {
        $actor = $this->manager();
        $role = $actor->roles()->sole();
        $this->patchJson($this->url($role), ['label' => 'Renamed', 'version' => 1])->assertOk()
            ->assertJsonPath('data.label', 'Renamed')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.code', $role->code)->assertJsonPath('data.permissions', ['ROLE_MANAGE']);
        $this->assertTrue(Gate::forUser($actor)->allows('ROLE_MANAGE'));
        $this->assertSame(1, $role->users()->count());
        $event = AuditEvent::where('action', 'role.renamed')->sole();
        $this->assertSame(['label' => $role->label, 'version' => 1], $event->changes['before']);
        $this->assertSame(['label' => 'Renamed', 'version' => 2], $event->changes['after']);
    }

    public function test_stale_rename_and_preview_are_rejected_without_further_writes(): void
    {
        $this->manager();
        $role = Role::factory()->create();
        $this->patchJson($this->url($role), ['label' => 'First change', 'version' => 1])->assertOk();
        $this->patchJson($this->url($role), ['label' => 'Stale change', 'version' => 1])->assertConflict();
        $this->postJson($this->url($role).'/permission-change-preview', ['version' => 1, 'permission_codes' => []])->assertConflict();
        $this->assertSame('First change', $role->fresh()->label);
        $this->assertSame(1, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_protected_role_cannot_be_renamed_or_previewed_for_editing(): void
    {
        $this->manager();
        $role = Role::factory()->create(['is_protected' => true]);
        $this->patchJson($this->url($role), ['label' => 'Changed', 'version' => 1])->assertConflict();
        $this->postJson($this->url($role).'/permission-change-preview', ['version' => 1, 'permission_codes' => []])->assertConflict();
        $this->assertSame(1, $role->fresh()->version);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_rename_rejects_code_permission_and_protection_changes(): void
    {
        $this->manager();
        $role = Role::factory()->create();
        foreach (['code' => 'OTHER', 'is_protected' => true, 'permission_codes' => []] as $field => $value) {
            $this->patchJson($this->url($role), ['label' => 'Changed', 'version' => 1, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson($this->url($role), ['label' => 'Changed'])->assertUnprocessable();
        $this->assertSame(1, $role->fresh()->version);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_preview_shows_exact_delta_and_only_assigned_account_ids_without_writing(): void
    {
        $this->manager();
        $role = Role::factory()->create();
        $keep = Permission::factory()->create(['code' => 'USER_CREATE']);
        $remove = Permission::factory()->create(['code' => 'USER_ASSIGN_ROLE']);
        Permission::factory()->create(['code' => 'USER_RESET_ACCESS']);
        $role->permissions()->attach([$keep->id, $remove->id]);
        $member = User::factory()->create();
        $role->users()->attach($member->id, ['assigned_by' => $member->id, 'assigned_at' => now()]);
        User::factory()->create();
        $response = $this->postJson($this->url($role).'/permission-change-preview', [
            'version' => 1, 'permission_codes' => ['USER_RESET_ACCESS', 'USER_CREATE'],
        ])->assertOk()->assertJsonPath('data.before', ['USER_ASSIGN_ROLE', 'USER_CREATE'])
            ->assertJsonPath('data.after', ['USER_CREATE', 'USER_RESET_ACCESS'])
            ->assertJsonPath('data.added', ['USER_RESET_ACCESS'])->assertJsonPath('data.removed', ['USER_ASSIGN_ROLE'])
            ->assertJsonPath('data.affected_accounts.ids', [$member->id])->assertJsonPath('data.affected_accounts.total', 1);
        $this->assertStringNotContainsString($member->email, $response->getContent());
        $this->assertSame(['USER_ASSIGN_ROLE', 'USER_CREATE'], $role->permissions()->orderBy('code')->pluck('code')->all());
        $this->assertSame(1, $role->fresh()->version);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_empty_proposal_means_remove_all_and_affected_accounts_are_paginated(): void
    {
        $actor = $this->manager();
        $role = Role::factory()->create();
        $permission = Permission::where('code', 'ROLE_MANAGE')->sole();
        $role->permissions()->attach($permission->id);
        foreach (User::factory()->count(3)->create() as $user) {
            $role->users()->attach($user->id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        }
        $this->postJson($this->url($role).'/permission-change-preview', [
            'version' => 1, 'permission_codes' => [], 'per_page' => 2, 'page' => 2,
        ])->assertOk()->assertJsonPath('data.after', [])->assertJsonPath('data.removed', ['ROLE_MANAGE'])
            ->assertJsonPath('data.affected_accounts.total', 3)->assertJsonPath('data.affected_accounts.current_page', 2)
            ->assertJsonCount(1, 'data.affected_accounts.ids');
        $this->assertSame(1, $role->permissions()->count());
    }

    public function test_preview_rejects_unknown_duplicate_or_missing_codes_and_invalid_pagination(): void
    {
        $this->manager();
        $role = Role::factory()->create();
        foreach ([
            ['version' => 1],
            ['version' => 1, 'permission_codes' => ['UNKNOWN']],
            ['version' => 1, 'permission_codes' => ['role_manage']],
            ['version' => 1, 'permission_codes' => ['ROLE_MANAGE', 'ROLE_MANAGE']],
            ['version' => 1, 'permission_codes' => [], 'per_page' => 101],
            ['version' => 1, 'permission_codes' => [], 'page' => 0],
            ['version' => 1, 'permission_codes' => [], 'user_id' => 1],
        ] as $data) {
            $this->postJson($this->url($role).'/permission-change-preview', $data)->assertUnprocessable();
        }
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_revoked_management_permission_cannot_make_further_changes(): void
    {
        $actor = $this->manager();
        $role = Role::factory()->create();
        $actor->roles()->detach();
        $this->patchJson($this->url($role), ['label' => 'Changed', 'version' => 1])->assertForbidden();
        $this->assertSame(1, $role->fresh()->version);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_renaming_to_same_label_does_not_increment_version_or_add_audit(): void
    {
        $this->manager();
        $role = Role::factory()->create();
        $this->patchJson($this->url($role), ['label' => $role->label, 'version' => 1])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_temporary_employee_cannot_create_rename_or_preview_roles(): void
    {
        $actor = $this->manager();
        $role = Role::factory()->create();
        $actor->forceFill(['must_change_password' => true])->save();
        $this->postJson('/api/v1/access/roles', ['code' => 'NEW_ROLE', 'label' => 'New'])->assertForbidden();
        $this->patchJson($this->url($role), ['label' => 'Changed', 'version' => 1])->assertForbidden();
        $this->postJson($this->url($role).'/permission-change-preview', ['version' => 1, 'permission_codes' => []])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['code' => 'NEW_ROLE']);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_missing_or_invalid_role_identifier_does_not_create_an_audit(): void
    {
        $this->manager();
        foreach (['not-a-uuid', (string) Str::uuid()] as $id) {
            $this->patchJson('/api/v1/access/roles/'.$id, ['label' => 'Changed', 'version' => 1])->assertNotFound();
            $this->postJson('/api/v1/access/roles/'.$id.'/permission-change-preview', ['version' => 1, 'permission_codes' => []])->assertNotFound();
        }
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_failed_audit_rolls_back_role_creation(): void
    {
        $this->manager();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            $this->postJson('/api/v1/access/roles', ['code' => 'ROLLBACK', 'label' => 'Rollback']);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseMissing('roles', ['code' => 'ROLLBACK']);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_failed_audit_rolls_back_rename_and_version(): void
    {
        $this->manager();
        $role = Role::factory()->create();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            $this->patchJson($this->url($role), ['label' => 'Must rollback', 'version' => 1]);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertSame($role->label, $role->fresh()->label);
        $this->assertSame(1, $role->fresh()->version);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::firstOrCreate(['code' => 'ROLE_MANAGE'], ['module' => 'access', 'label' => 'Manage roles']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        $this->actingAs($user, 'web');

        return $user;
    }

    private function url(Role $role): string
    {
        return '/api/v1/access/roles/'.$role->id;
    }
}
