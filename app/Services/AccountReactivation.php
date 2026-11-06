<?php

namespace App\Services;

use App\Models\AccountReactivationRequest;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class AccountReactivation
{
    public function consumeApproved(User $user): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reactivation must share the authenticated login transaction.');
        }
        if ($user->inactivity_suspended_at === null) {
            return;
        }
        $request = AccountReactivationRequest::where('active_user_id', $user->id)
            ->where('suspension_version', $user->inactivity_version)->where('status', 'APPROVED')->lockForUpdate()->first();
        if ($request === null) {
            return;
        }
        $user->inactivity_suspended_at = null;
        $user->save();
        $request->status = 'COMPLETED';
        $request->active_user_id = null;
        $request->completed_at = now('UTC');
        $request->version++;
        $request->save();
        AuditEvent::create([
            'actor_id' => $user->id, 'request_id' => (string) Str::uuid(),
            'action' => 'account.inactivity.reactivated', 'object_type' => 'user', 'object_id' => (string) $user->id,
            'result' => 'SUCCESS', 'reason' => 'approved_fresh_login',
            'changes' => ['before' => ['status' => 'SUSPENDED'], 'after' => ['status' => 'ACTIVE', 'version' => $user->inactivity_version]],
            'occurred_at' => now('UTC'),
        ]);
    }
}
