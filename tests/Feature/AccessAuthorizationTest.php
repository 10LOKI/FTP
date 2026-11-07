<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessPermissionCatalog;
use Database\Seeders\AccessPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Tests\TestCase;

class AccessAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeding_is_repeatable_and_grants_nobody_access(): void
    {
        $this->seed(AccessPermissionSeeder::class);
        $ids = Permission::orderBy('code')->pluck('id')->all();
        $this->seed(AccessPermissionSeeder::class);

        $this->assertSame(count(AccessPermissionCatalog::LABELS), Permission::count());
        $this->assertSame($ids, Permission::orderBy('code')->pluck('id')->all());
        $this->assertDatabaseCount('role_permissions', 0);
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('ROLE_MANAGE'));
    }

    public function test_permission_label_can_change_but_code_cannot_be_renamed(): void
    {
        $permission = Permission::factory()->create(['code' => 'ROLE_MANAGE']);
        $permission->update(['label' => 'New label']);
        $this->assertSame('New label', $permission->fresh()->label);
        $this->expectException(LogicException::class);
        $permission->update(['code' => 'USER_CREATE']);
    }

    public function test_real_gates_use_assignments_and_recheck_revoked_access(): void
    {
        $user = User::factory()->create();
        $role = $this->grant($user, ['ROLE_MANAGE', 'USER_ASSIGN_ROLE']);
        $user->load('roles.permissions');
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('ROLE_MANAGE'));
        $this->assertTrue($gate->allows('USER_ASSIGN_ROLE'));
        $this->assertFalse($gate->allows('USER_CREATE'));
        $this->assertFalse($gate->allows('UNDEFINED_PERMISSION'));
        $role->permissions()->detach();
        $this->assertFalse($gate->allows('ROLE_MANAGE'));
        $this->assertFalse($gate->allows('USER_ASSIGN_ROLE'));
    }

    public function test_each_catalog_permission_is_independent_and_uses_its_own_gate(): void
    {
        foreach (array_keys(AccessPermissionCatalog::LABELS) as $granted) {
            $user = User::factory()->create();
            $this->grant($user, [$granted]);
            foreach (array_keys(AccessPermissionCatalog::LABELS) as $checked) {
                $this->assertSame($granted === $checked, Gate::forUser($user)->allows($checked), $granted.' versus '.$checked);
            }
        }
    }

    public function test_catalogs_require_authentication_and_actual_management_permission(): void
    {
        foreach (['roles', 'permissions'] as $catalog) {
            $this->getJson('/api/v1/access/'.$catalog)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['roles', 'permissions'] as $catalog) {
            $this->getJson('/api/v1/access/'.$catalog)->assertForbidden();
        }
    }

    public function test_management_reads_role_composition_without_account_details(): void
    {
        $user = User::factory()->create();
        $role = $this->grant($user, ['ROLE_MANAGE']);
        $this->actingAs($user, 'web');
        $response = $this->getJson('/api/v1/access/roles')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([
            'id' => $role->id, 'code' => $role->code, 'label' => $role->label,
            'version' => 1, 'is_protected' => false, 'permissions' => ['ROLE_MANAGE'],
        ], $response->json('data.0'));
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->getJson('/api/v1/access/permissions')->assertOk()->assertJsonPath('data.0.code', 'ROLE_MANAGE');
        $this->assertSame(0, AuditEvent::where('action', 'like', 'role.%')->count());
    }

    public function test_catalog_filters_and_page_sizes_are_validated(): void
    {
        $user = User::factory()->create();
        $this->grant($user, ['ROLE_MANAGE']);
        Role::factory()->count(2)->create();
        $this->actingAs($user, 'web');
        $this->getJson('/api/v1/access/roles?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        foreach (['roles', 'permissions'] as $catalog) {
            $this->getJson('/api/v1/access/'.$catalog.'?per_page=101')->assertUnprocessable();
            $this->getJson('/api/v1/access/'.$catalog.'?page=0')->assertUnprocessable();
            $this->getJson('/api/v1/access/'.$catalog.'?user_id=1')->assertUnprocessable();
        }
    }

    public function test_own_summary_is_private_and_rejects_target_user_filters(): void
    {
        $this->getJson('/api/v1/me/permissions')->assertUnauthorized();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->grant($user, ['USER_CREATE']);
        $this->grant($other, ['ROLE_MANAGE']);
        $this->actingAs($user, 'web');
        $this->getJson('/api/v1/me/permissions')->assertOk()->assertExactJson(['data' => ['permissions' => ['USER_CREATE']]]);
        $this->getJson('/api/v1/me/permissions?user_id='.$other->id)->assertUnprocessable();
    }

    public function test_real_role_permission_opens_existing_role_catalog_and_revocation_closes_it(): void
    {
        $user = User::factory()->create();
        $role = $this->grant($user, ['USER_ASSIGN_ROLE']);
        $this->actingAs($user, 'web');
        $this->getJson('/api/v1/roles')->assertOk();
        $this->getJson('/api/v1/access/roles')->assertForbidden();
        $role->permissions()->detach();
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_pending_customers_and_temporary_accounts_cannot_use_new_read_apis(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'EN_ATTENTE']);
        $this->grant($profile->user, ['ROLE_MANAGE']);
        $this->actingAs($profile->user, 'web');
        $this->getJson('/api/v1/me/permissions')->assertForbidden();
        $this->getJson('/api/v1/access/roles')->assertForbidden();
        $profile->user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/access/permissions')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_role_permissions_do_not_override_customer_ownership_gates(): void
    {
        $user = User::factory()->create();
        $this->grant($user, ['CART_MANAGE_OWN', 'ORDER_SUBMIT_OWN']);
        $this->assertFalse(Gate::forUser($user)->allows('CART_MANAGE_OWN'));
        $this->assertFalse(Gate::forUser($user)->allows('ORDER_SUBMIT_OWN'));
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->assertTrue(Gate::forUser($customer->user)->allows('CART_MANAGE_OWN'));
        $this->assertTrue(Gate::forUser($customer->user)->allows('ORDER_SUBMIT_OWN'));
    }

    /** @param list<string> $codes */
    private function grant(User $user, array $codes): Role
    {
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        foreach ($codes as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'access', 'label' => $code]);
            $role->permissions()->attach($permission->id);
        }

        return $role;
    }
}
