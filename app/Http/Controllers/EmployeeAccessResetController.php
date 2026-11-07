<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetEmployeeAccessRequest;
use App\Http\Resources\EmployeeProvisioningResource;
use App\Models\AuditEvent;
use App\Models\EmployeeAccessDelivery;
use App\Models\Role;
use App\Models\User;
use App\Services\AdministratorAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EmployeeAccessResetController extends Controller
{
    private function isUsableAdministrator(User $user): bool
    {
        return app(AdministratorAccess::class)->isUsable($user);
    }

    public function __invoke(ResetEmployeeAccessRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        try {
            $delivery = DB::transaction(function () use ($request, $data, $user): EmployeeAccessDelivery {
                Role::orderBy('id')->lockForUpdate()->get();
                User::orderBy('id')->lockForUpdate()->get();
                $target = User::whereKey($user->id)->firstOrFail();
                Gate::authorize('USER_RESET_ACCESS');
                $existing = EmployeeAccessDelivery::whereKey($data['request_id'])->lockForUpdate()->first();
                if ($existing !== null) {
                    abort_unless($existing->user_id === $target->id && $existing->created_by === $request->user()->id
                        && $existing->requested_access_version === (int) $data['expected_access_version'], 409, 'This request ID has already been used.');

                    return $existing;
                }
                abort_if($target->id === $request->user()->id || $target->roles()->where('is_protected', true)->exists(), 409, 'Protected or self access requires the administrator-safety workflow.');
                abort_unless($target->access_version > 0, 409, 'Only provisioned employee access can be reissued here.');
                abort_unless($target->access_version === (int) $data['expected_access_version'], 409, 'Access has changed. Refresh the delivery status before retrying.');
                $hours = filter_var(config('employee_access.validity_hours'), FILTER_VALIDATE_INT);
                abort_unless($hours !== false && $hours >= 1 && $hours <= 168, 503, 'Configure a temporary access lifetime between 1 and 168 hours.');

                $wasAdministrator = $this->isUsableAdministrator($target);
                $target->password = bin2hex(random_bytes(24));
                $target->must_change_password = true;
                $target->temporary_password_expires_at = now('UTC')->addHours($hours);
                $target->access_version++;
                $target->setRememberToken(Str::random(60));
                $target->save();
                if ($wasAdministrator) {
                    $remaining = User::whereKeyNot($target->id)->get()->contains(
                        fn (User $candidate): bool => $this->isUsableAdministrator($candidate),
                    );
                    abort_unless($remaining, 409, 'Resetting this account would remove the last usable administrator.');
                }

                EmployeeAccessDelivery::where('user_id', $target->id)->whereIn('status', ['PENDING', 'RETRY'])
                    ->update(['status' => 'EXPIRED', 'last_error_code' => 'ACCESS_SUPERSEDED', 'next_attempt_at' => null]);
                $delivery = new EmployeeAccessDelivery([
                    'user_id' => $target->id, 'created_by' => $request->user()->id,
                    'expires_at' => $target->temporary_password_expires_at,
                ]);
                $delivery->id = $data['request_id'];
                $delivery->requested_access_version = (int) $data['expected_access_version'];
                $delivery->save();
                AuditEvent::create([
                    'actor_id' => $request->user()->id, 'request_id' => $data['request_id'],
                    'action' => 'employee.access.reset', 'object_type' => 'user', 'object_id' => (string) $target->id,
                    'result' => 'SUCCESS', 'reason' => 'authorized_reissue',
                    'changes' => ['after' => ['delivery_id' => $delivery->id, 'access_version' => $target->access_version, 'must_change_password' => true]],
                    'occurred_at' => now('UTC'),
                ]);

                return $delivery->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! EmployeeAccessDelivery::whereKey($data['request_id'])->exists()) {
                throw $exception;
            }
            abort(409, 'This request ID has already been used.');
        }

        return (new EmployeeProvisioningResource($delivery))->response()->setStatusCode(202);
    }
}
