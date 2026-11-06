<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EffectivePermissions
{
    /** @return list<string> */
    public function codes(User $user): array
    {
        return $this->query($user)->orderBy('code')->pluck('code')->all();
    }

    public function allows(User $user, string $code): bool
    {
        return $this->query($user)->where('code', $code)->exists();
    }

    /** @return Builder<Permission> */
    private function query(User $user): Builder
    {
        return Permission::query()->whereHas('roles', function (Builder $roles) use ($user): void {
            $roles->whereHas('users', function (Builder $users) use ($user): void {
                $users->whereKey($user->getAuthIdentifier());
            });
        });
    }
}
