<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmRolePermissionsRequest;
use App\Http\Requests\PreviewRolePermissionsRequest;
use App\Http\Requests\RenameRoleRequest;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Resources\AccessRoleResource;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\RolePermissionChange;
use App\Services\RolePermissionConfirmation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class RoleEditorController extends Controller
{
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $role = DB::transaction(function () use ($request, $data): Role {
                Gate::authorize('ROLE_MANAGE');
                $role = Role::create($data);
                $this->audit($request->user(), $role, 'role.created', null, [
                    'code' => $role->code, 'label' => $role->label, 'version' => 1,
                ]);

                return $role->refresh()->load('permissions');
            });
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'This role code was created by another operation. Refresh before trying again.');
        }

        return (new AccessRoleResource($role))->response()->setStatusCode(201);
    }

    public function rename(RenameRoleRequest $request, Role $role): AccessRoleResource
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($request, $role, $data): Role {
            $current = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('ROLE_MANAGE');
            $this->checkEditableVersion($current, (int) $data['version']);
            if ($current->label !== $data['label']) {
                $before = ['label' => $current->label, 'version' => $current->version];
                $current->label = $data['label'];
                $current->version++;
                $current->save();
                $this->audit($request->user(), $current, 'role.renamed', $before, [
                    'label' => $current->label, 'version' => $current->version,
                ]);
            }

            return $current->load('permissions');
        });

        return new AccessRoleResource($updated);
    }

    public function preview(PreviewRolePermissionsRequest $request, Role $role, RolePermissionConfirmation $confirmation): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $role, $data, $confirmation): JsonResponse {
            $current = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('ROLE_MANAGE');
            $this->checkEditableVersion($current, (int) $data['version']);
            $before = $current->permissions()->orderBy('code')->pluck('code')->all();
            $after = $data['permission_codes'];
            sort($after, SORT_STRING);
            $accounts = $current->users()->orderBy('users.id')->paginate(
                (int) ($data['per_page'] ?? 20), ['users.id'], 'page', (int) ($data['page'] ?? 1),
            );

            return response()->json(['data' => [
                'confirmation' => $confirmation->issue($request->user(), $current, $after),
                'roleId' => $current->id,
                'version' => $current->version,
                'before' => $before,
                'after' => $after,
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
                'affected_accounts' => [
                    'ids' => $accounts->getCollection()->modelKeys(),
                    'total' => $accounts->total(),
                    'current_page' => $accounts->currentPage(),
                    'per_page' => $accounts->perPage(),
                    'last_page' => $accounts->lastPage(),
                ],
            ]]);
        });
    }

    public function confirm(ConfirmRolePermissionsRequest $request, Role $role, RolePermissionChange $change): AccessRoleResource
    {
        $data = $request->validated();

        return new AccessRoleResource($change->apply(
            $request->user(), $role, (int) $data['version'], $data['permission_codes'], $data['confirmation_token'],
        ));
    }

    private function checkEditableVersion(Role $role, int $version): void
    {
        abort_if($role->is_protected, 409, 'Protected roles cannot be edited through this workflow.');
        abort_if($role->version !== $version, 409, 'This role has changed. Refresh before continuing.');
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, Role $role, string $action, ?array $before, array $after): void
    {
        AuditEvent::create([
            'actor_id' => $actor->getAuthIdentifier(),
            'request_id' => (string) Str::uuid(),
            'action' => $action,
            'object_type' => 'role',
            'object_id' => $role->id,
            'result' => 'SUCCESS',
            'reason' => null,
            'changes' => ['before' => $before, 'after' => $after],
            'occurred_at' => now('UTC'),
        ]);
    }
}
