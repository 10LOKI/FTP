<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RolePermissionChange
{
    public function __construct(private RolePermissionConfirmation $confirmation) {}

    /** @param list<string> $proposed */
    public function apply(User $actor, Role $role, int $version, array $proposed, string $token): Role
    {
        return DB::transaction(function () use ($actor, $role, $version, $proposed, $token): Role {
            $roles = Role::orderBy('id')->lockForUpdate()->get();
            $current = $roles->firstWhere('id', $role->id);
            abort_if($current === null, 404);
            abort_if($current->is_protected || $current->version !== $version, 409, 'The role has changed or is protected. Review again.');
            $users = User::orderBy('id')->lockForUpdate()->get();
            CustomerProfile::where('approval', '!=', 'APPROUVE')->orderBy('id')->lockForUpdate()->pluck('user_id')->all();
            $this->confirmation->verify($actor, $current, $proposed, $token);
            $before = $current->permissions()->orderBy('code')->pluck('code')->all();
            sort($proposed, SORT_STRING);
            $changed = array_merge(array_diff($proposed, $before), array_diff($before, $proposed));
            abort_if(Permission::whereIn('code', $changed)->where('is_protected', true)->exists(),
                409, 'Protected permission assignments cannot be changed through this workflow.');
            if ($before === $proposed) {
                return $current->load('permissions');
            }
            $definitions = Permission::whereIn('code', $proposed)->orderBy('code')->lockForUpdate()->get();
            abort_unless($definitions->pluck('code')->all() === $proposed, 409, 'The permission catalog has changed. Review again.');
            $current->permissions()->sync($definitions->modelKeys());
            $administratorRemains = $users->contains(
                fn (User $user): bool => app(AdministratorAccess::class)->isUsable($user),
            );
            abort_unless($administratorRemains, 409, 'Keep ROLE_MANAGE and USER_ASSIGN_ROLE on at least one usable account.');
            $oldVersion = $current->version;
            $current->version++;
            $current->save();
            AuditEvent::create([
                'actor_id' => $actor->id,
                'request_id' => (string) Str::uuid(),
                'action' => 'role.permissions_changed',
                'object_type' => 'role',
                'object_id' => $current->id,
                'result' => 'SUCCESS',
                'reason' => null,
                'changes' => [
                    'before' => ['permissions' => $before, 'version' => $oldVersion],
                    'after' => ['permissions' => $proposed, 'version' => $current->version],
                ],
                'occurred_at' => now('UTC'),
            ]);

            return $current->load('permissions');
        }, 3);
    }
}
