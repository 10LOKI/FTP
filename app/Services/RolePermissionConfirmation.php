<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use JsonException;

class RolePermissionConfirmation
{
    /** @param list<string> $proposed
     * @return array{token: string, expiresAt: string}
     */
    public function issue(User $actor, Role $role, array $proposed): array
    {
        $actor = $actor->fresh();
        Gate::forUser($actor)->authorize('ROLE_MANAGE');
        abort_if($actor->must_change_password, 403, 'Change your temporary password first.');
        $proposed = $this->canonical($proposed);
        $expires = now('UTC')->addMinutes(10);
        $payload = [
            'purpose' => 'role.permission-change',
            'actorId' => (string) $actor->id,
            'actorVersion' => $actor->access_version,
            'roleId' => $role->id,
            'proposed' => $proposed,
            'snapshot' => $this->snapshot($role, $proposed),
            'expires' => $expires->getTimestamp(),
        ];

        return ['token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'expiresAt' => $expires->toIso8601String()];
    }

    /** @param list<string> $proposed */
    public function verify(User $actor, Role $role, array $proposed, string $token): void
    {
        $actor = $actor->fresh();
        Gate::forUser($actor)->authorize('ROLE_MANAGE');
        abort_if($actor->must_change_password, 403, 'Change your temporary password first.');
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            abort(422, 'Invalid permission-change confirmation.');
        }
        abort_unless(is_array($payload) && ($payload['purpose'] ?? null) === 'role.permission-change'
            && is_int($payload['expires'] ?? null), 422, 'Invalid permission-change confirmation.');
        abort_unless(($payload['actorId'] ?? null) === (string) $actor->id && ($payload['roleId'] ?? null) === $role->id,
            403, 'This confirmation belongs to a different account or role.');
        abort_if($payload['expires'] <= now('UTC')->getTimestamp(), 409, 'The preview has expired. Review the changes again.');
        abort_unless(($payload['actorVersion'] ?? null) === $actor->access_version
            && ($payload['proposed'] ?? null) === $this->canonical($proposed)
            && ($payload['snapshot'] ?? null) === $this->snapshot($role, $proposed),
            409, 'The preview has changed. Review the changes again.');
    }

    /** @param list<string> $codes
     * @return list<string>
     */
    private function canonical(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);

        return $codes;
    }

    /** @param list<string> $proposed */
    private function snapshot(Role $role, array $proposed): string
    {
        $current = Role::whereKey($role->id)->firstOrFail();
        abort_if($current->is_protected, 409, 'Protected roles cannot be edited through this workflow.');
        $assigned = $current->permissions()->orderBy('code')->pluck('code')->all();
        $definitions = Permission::whereIn('code', array_merge($assigned, $proposed))->orderBy('code')
            ->get(['id', 'code', 'is_protected'])->toArray();
        abort_if(array_diff($proposed, array_column($definitions, 'code')) !== [], 409, 'The permission catalog has changed. Review again.');
        $members = $current->users()->orderBy('users.id')->pluck('users.id')->all();

        return hash('sha256', json_encode([
            'version' => $current->version,
            'assigned' => $assigned,
            'definitions' => $definitions,
            'members' => $members,
        ], JSON_THROW_ON_ERROR));
    }
}
