<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InactivitySuspension
{
    public function check(User $user): void
    {
        $current = $user->fresh();
        if ($current !== null && $current->inactivity_suspended_at === null
            && app(AccountInactivityPolicy::class)->assess($current)['status'] === 'DUE') {
            $this->run($current->id);
        }
    }

    /** @return array{suspended: int, protected: int, missing: int} */
    public function run(?int $onlyUserId = null): array
    {
        return DB::transaction(function () use ($onlyUserId): array {
            Role::orderBy('id')->lockForUpdate()->get();
            $users = User::orderBy('id')->lockForUpdate()->get();
            CustomerProfile::orderBy('id')->lockForUpdate()->get();
            $now = CarbonImmutable::now('UTC');
            $counts = ['suspended' => 0, 'protected' => 0, 'missing' => 0];
            $guard = app(AdministratorAccess::class);
            foreach ($users as $user) {
                if (($onlyUserId !== null && $user->id !== $onlyUserId) || $user->inactivity_suspended_at !== null) {
                    continue;
                }
                $assessment = app(AccountInactivityPolicy::class)->assess($user, $now);
                if ($assessment['status'] === 'MISSING_BASELINE') {
                    $counts['missing']++;
                }
                if ($assessment['status'] !== 'DUE') {
                    continue;
                }
                if ($guard->isUsable($user) && ! $users->contains(
                    fn (User $other): bool => $other->id !== $user->id && $guard->isUsable($other),
                )) {
                    $counts['protected']++;

                    continue;
                }
                $user->inactivity_suspended_at = $now;
                $user->inactivity_version++;
                $user->save();
                AuditEvent::create([
                    'actor_id' => null, 'request_id' => (string) Str::uuid(),
                    'action' => 'account.inactivity.suspended', 'object_type' => 'user', 'object_id' => (string) $user->id,
                    'result' => 'SUCCESS', 'reason' => 'four_calendar_months',
                    'changes' => ['before' => ['status' => 'ACTIVE'], 'after' => ['status' => 'SUSPENDED', 'version' => $user->inactivity_version]],
                    'occurred_at' => $now,
                ]);
                $counts['suspended']++;
            }

            return $counts;
        }, 3);
    }
}
