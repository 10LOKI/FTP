<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteRoleRequest;
use App\Models\AuditEvent;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class RoleDeletionController extends Controller
{
    public function preview(Role $role): JsonResponse
    {
        Gate::authorize('ROLE_DELETE');
        $assigned = $role->users()->count();
        $protectedPermissions = $role->permissions()->where('is_protected', true)->exists();

        return response()->json(['data' => [
            'roleId' => $role->id, 'code' => $role->code, 'label' => $role->label,
            'version' => $role->version,
            'assigned_accounts' => $assigned,
            'is_protected' => $role->is_protected,
            'has_protected_permissions' => $protectedPermissions,
            'can_delete' => ! $role->is_protected && $assigned === 0 && ! $protectedPermissions,
        ]]);
    }

    public function destroy(DeleteRoleRequest $request, Role $role): JsonResponse
    {
        DB::transaction(function () use ($request, $role): void {
            $current = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('ROLE_DELETE');
            abort_if($current->version !== (int) $request->validated('version'), 409, 'This role has changed. Review deletion again.');
            abort_if($current->is_protected, 409, 'Protected roles cannot be deleted.');
            abort_if($current->users()->exists(), 409, 'This role is assigned to accounts. Reassign them before deletion.');
            abort_if($current->permissions()->where('is_protected', true)->exists(), 409, 'This role has protected permission assignments.');
            $before = [
                'code' => $current->code, 'label' => $current->label, 'version' => $current->version,
                'permissions' => $current->permissions()->orderBy('code')->pluck('code')->all(),
            ];
            $current->permissions()->detach();
            $current->delete();
            AuditEvent::create([
                'actor_id' => $request->user()->id, 'request_id' => (string) Str::uuid(),
                'action' => 'role.deleted', 'object_type' => 'role', 'object_id' => $role->id,
                'result' => 'SUCCESS', 'reason' => null,
                'changes' => ['before' => $before, 'after' => null],
                'occurred_at' => now('UTC'),
            ]);
        }, 3);

        return response()->json(null, 204);
    }
}
