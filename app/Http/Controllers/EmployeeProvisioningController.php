<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Resources\EmployeeProvisioningResource;
use App\Models\AuditEvent;
use App\Models\EmployeeAccessDelivery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeProvisioningController extends Controller
{
    public function store(StoreEmployeeRequest $request): EmployeeProvisioningResource
    {
        $data = $request->validated();
        $hours = filter_var(config('employee_access.validity_hours'), FILTER_VALIDATE_INT);
        abort_unless($hours !== false && $hours >= 1 && $hours <= 168, 503, 'Configure a temporary access lifetime between 1 and 168 hours.');
        try {
            $delivery = DB::transaction(function () use ($request, $data, $hours): EmployeeAccessDelivery {
                Gate::authorize('USER_CREATE');
                Gate::authorize('USER_ASSIGN_ROLE');
                $roles = Role::whereIn('id', $data['role_ids'])->orderBy('id')->lockForUpdate()->get();
                if ($roles->count() !== count($data['role_ids']) || $roles->contains(fn (Role $role): bool => $role->is_protected)) {
                    throw ValidationException::withMessages(['role_ids' => 'Refresh the ordinary role selection before creating this account.']);
                }
                $user = new User(['name' => $data['email'], 'email' => $data['email'], 'password' => bin2hex(random_bytes(24))]);
                $user->must_change_password = true;
                $user->temporary_password_expires_at = now('UTC')->addHours($hours);
                $user->access_version = 1;
                $user->save();
                foreach ($roles as $role) {
                    $user->roles()->attach($role->id, ['assigned_by' => $request->user()->id, 'assigned_at' => now('UTC')]);
                }
                $delivery = EmployeeAccessDelivery::create(['user_id' => $user->id, 'created_by' => $request->user()->id, 'expires_at' => $user->temporary_password_expires_at]);
                AuditEvent::create([
                    'actor_id' => $request->user()->id, 'request_id' => (string) Str::uuid(),
                    'action' => 'employee.provisioned', 'object_type' => 'user', 'object_id' => (string) $user->id,
                    'result' => 'SUCCESS', 'reason' => 'temporary_access_pending',
                    'changes' => ['after' => ['role_ids' => $roles->modelKeys(), 'delivery_id' => $delivery->id, 'must_change_password' => true]],
                    'occurred_at' => now('UTC'),
                ]);

                return $delivery->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! User::where('email', $data['email'])->exists()) {
                throw $exception;
            }
            throw ValidationException::withMessages(['email' => 'This email is already registered.']);
        }

        return new EmployeeProvisioningResource($delivery);
    }

    public function show(EmployeeAccessDelivery $delivery): EmployeeProvisioningResource
    {
        return new EmployeeProvisioningResource($delivery);
    }
}
