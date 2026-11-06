<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EffectivePermissions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectivePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_without_roles_have_no_permissions_and_unknown_codes_are_denied(): void
    {
        $user = User::factory()->create();
        Permission::factory()->create(['code' => 'ROLE_MANAGE']);
        $service = new EffectivePermissions;

        $this->assertSame([], $service->codes($user));
        $this->assertFalse($service->allows($user, 'ROLE_MANAGE'));
        $this->assertFalse($service->allows($user, 'UNKNOWN'));
    }

    public function test_multiple_roles_combine_permissions_without_duplicates_or_other_users_access(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->assignRole($user);
        $second = $this->assignRole($user);
        $unrelated = $this->assignRole($other);
        $read = Permission::factory()->create(['code' => 'AUDIT_READ']);
        $manage = Permission::factory()->create(['code' => 'ROLE_MANAGE']);
        $delete = Permission::factory()->create(['code' => 'ROLE_DELETE']);
        $first->permissions()->attach([$read->id, $manage->id]);
        $second->permissions()->attach($read->id);
        $unrelated->permissions()->attach($delete->id);
        $service = new EffectivePermissions;

        $this->assertSame(['AUDIT_READ', 'ROLE_MANAGE'], $service->codes($user));
        $this->assertTrue($service->allows($user, 'ROLE_MANAGE'));
        $this->assertFalse($service->allows($user, 'ROLE_DELETE'));
        $this->assertFalse($service->allows($user, 'role_manage'));
    }

    public function test_role_rename_preserves_access_and_permission_removal_is_immediate(): void
    {
        $user = User::factory()->create();
        $role = $this->assignRole($user);
        $permission = Permission::factory()->create(['code' => 'ROLE_MANAGE']);
        $role->permissions()->attach($permission->id);
        $user->load('roles.permissions');
        $service = new EffectivePermissions;

        $role->update(['label' => 'Renamed role']);
        $this->assertTrue($service->allows($user, 'ROLE_MANAGE'));
        $role->permissions()->detach($permission->id);
        $this->assertFalse($service->allows($user, 'ROLE_MANAGE'));
        $this->assertSame([], $service->codes($user));
    }

    public function test_removing_one_role_preserves_shared_permissions_until_last_assignment_is_removed(): void
    {
        $user = User::factory()->create();
        $first = $this->assignRole($user);
        $second = $this->assignRole($user);
        $permission = Permission::factory()->create(['code' => 'ROLE_MANAGE']);
        $first->permissions()->attach($permission->id);
        $second->permissions()->attach($permission->id);
        $user->load('roles.permissions');
        $service = new EffectivePermissions;

        $this->assertTrue($service->allows($user, 'ROLE_MANAGE'));
        $user->roles()->detach($first->id);
        $this->assertTrue($service->allows($user, 'ROLE_MANAGE'));
        $user->roles()->detach($second->id);
        $this->assertFalse($service->allows($user, 'ROLE_MANAGE'));
    }

    public function test_role_name_and_protected_flag_do_not_grant_implicit_permissions(): void
    {
        $user = User::factory()->create();
        $role = $this->assignRole($user);
        $role->forceFill(['code' => 'ADMIN', 'is_protected' => true])->save();

        $this->assertSame([], (new EffectivePermissions)->codes($user));
    }

    public function test_duplicate_permission_codes_are_rejected_by_storage(): void
    {
        Permission::factory()->create(['code' => 'ROLE_MANAGE']);
        $this->expectException(QueryException::class);
        Permission::factory()->create(['code' => 'ROLE_MANAGE']);
    }

    public function test_duplicate_role_permission_links_are_rejected_by_storage(): void
    {
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();
        $role->permissions()->attach($permission->id);
        $this->expectException(QueryException::class);
        $role->permissions()->attach($permission->id);
    }

    private function assignRole(User $user): Role
    {
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);

        return $role;
    }
}
