<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\User;
use Carbon\CarbonImmutable;

class AccountInactivityPolicy
{
    /** @return array{status: string, deadline: ?CarbonImmutable, source: ?string} */
    public function assess(User $user, ?CarbonImmutable $now = null): array
    {
        if ($user->inactivity_suspended_at !== null) {
            return ['status' => 'SUSPENDED', 'deadline' => null, 'source' => null];
        }
        $profile = CustomerProfile::where('user_id', $user->id)->first();
        if ($profile !== null && $profile->approval !== 'APPROUVE') {
            return ['status' => 'NOT_APPROVED', 'deadline' => null, 'source' => null];
        }
        $source = $user->last_login_at !== null ? 'last_login' : ($profile !== null ? 'approval' : 'creation');
        $baseline = $user->last_login_at ?? ($profile !== null ? $profile->decided_at : $user->created_at);
        if ($baseline === null) {
            return ['status' => 'MISSING_BASELINE', 'deadline' => null, 'source' => $source];
        }
        $deadline = CarbonImmutable::instance($baseline)->setTimezone('Africa/Casablanca')->addMonthsNoOverflow(4)->utc();
        $now ??= CarbonImmutable::now('UTC');

        return ['status' => $now->greaterThanOrEqualTo($deadline) ? 'DUE' : 'CURRENT', 'deadline' => $deadline, 'source' => $source];
    }
}
