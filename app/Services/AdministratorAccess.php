<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\User;

class AdministratorAccess
{
    public function isUsable(User $user): bool
    {
        if ($user->must_change_password || $user->inactivity_suspended_at !== null
            || CustomerProfile::where('user_id', $user->id)->where('approval', '!=', 'APPROUVE')->exists()) {
            return false;
        }
        foreach (AccessPermissionCatalog::ADMINISTRATOR_PERMISSIONS as $code) {
            if (! app(EffectivePermissions::class)->allows($user, $code)) {
                return false;
            }
        }

        return true;
    }
}
