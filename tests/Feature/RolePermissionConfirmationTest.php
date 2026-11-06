<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RolePermissionConfirmation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RolePermissionConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_receipt_is_order_independent_encrypted_and_does_not_write(): void
    {
        $actor = $this->manager();
        $role = Role::factory()->create();
        Permission::factory()->create(['code' => 'USER_CREATE']);
        $service = new RolePermissionConfirmation;
        $receipt = $service->issue($actor, $role, ['USER_CREATE', 'ROLE_MANAGE']);
        $service->verify($actor, $role, ['ROLE_MANAGE', 'USER_CREATE'], $receipt['token']);
        $this->assertNotEmpty($receipt['expiresAt']);
        $this->assertStringNotContainsString('USER_CREATE', $receipt['token']);
        $this->assertSame(0, $role->permissions()->count());
        $this->assertDatabaseCount('audit_events', 0);
    }

    #[DataProvider('invalidations')]
    public function test_receipt_rejects_tampering_and_changed_context(string $kind, int $status): void
    {
        $this->freezeTime();
        $actor = $this->manager();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['code' => 'USER_CREATE']);
        $service = new RolePermissionConfirmation;
        if ($kind === 'removed_member') {
            $role->users()->attach($actor->id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        }
        $receipt = $service->issue($actor, $role, ['USER_CREATE']);
        $proposed = ['USER_CREATE'];
        switch ($kind) {
            case 'tampered':
                $receipt['token'] = 'not-a-valid-receipt';
                break;
            case 'expired':
                $this->travel(10)->minutes();
                break;
            case 'actor':
                $actor = $this->manager();
                break;
            case 'role':
                $role = Role::factory()->create();
                break;
            case 'proposal':
                $proposed = [];
                break;
            case 'version':
                Role::whereKey($role->id)->update(['version' => 2]);
                break;
            case 'assigned_permissions':
                $role->permissions()->attach($permission->id);
                break;
            case 'added_member':
                $role->users()->attach($actor->id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
                break;
            case 'removed_member':
                $role->users()->detach($actor->id);
                break;
            case 'protected_permission':
                $permission->forceFill(['is_protected' => true])->save();
                break;
            case 'protected_role':
                $role->forceFill(['is_protected' => true])->save();
                break;
            case 'credentials':
                $actor->forceFill(['access_version' => 1])->save();
                break;
            case 'revoked':
                $actor->roles()->detach();
                break;
            case 'temporary':
                $actor->forceFill(['must_change_password' => true])->save();
                break;
        }
        try {
            $service->verify($actor, $role, $proposed, $receipt['token']);
            $this->fail('Expected the receipt to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $status);
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    /** @return array<string, array{string, int}> */
    public static function invalidations(): array
    {
        return [
            'tampering' => ['tampered', 422],
            'expiry' => ['expired', 409],
            'different actor' => ['actor', 403],
            'different role' => ['role', 403],
            'different proposal' => ['proposal', 409],
            'new version' => ['version', 409],
            'changed grants' => ['assigned_permissions', 409],
            'new account' => ['added_member', 409],
            'removed account' => ['removed_member', 409],
            'changed protection' => ['protected_permission', 409],
            'protected role' => ['protected_role', 409],
            'credential reset' => ['credentials', 409],
            'lost permission' => ['revoked', 403],
            'temporary account' => ['temporary', 403],
        ];
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::firstOrCreate(['code' => 'ROLE_MANAGE'], ['module' => 'access', 'label' => 'Manage roles']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);

        return $user;
    }
}
