<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeLoginRequest;
use App\Http\Requests\ReplaceTemporaryPasswordRequest;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AccountReactivation;
use App\Services\InactivitySuspension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeSessionController extends Controller
{
    public function store(EmployeeLoginRequest $request): JsonResponse
    {
        abort_if(Auth::guard('web')->check(), 409, 'Sign out before opening another session.');
        $data = $request->validated();
        try {
            $candidate = User::where('email', $data['email'])->first();
            $valid = (new Timebox)->call(
                fn (): bool => $candidate !== null && $candidate->access_version > 0 && Hash::check($data['password'], $candidate->password),
                200000,
            );
            if (! $valid) {
                $this->audit(null, 'employee.login', 'REFUSED', 'invalid_credentials');

                return response()->json(['code' => 'INVALID_CREDENTIALS', 'message' => 'Identifiants invalides.'], 401);
            }
            app(InactivitySuspension::class)->check($candidate);

            return DB::transaction(function () use ($candidate, $data, $request): JsonResponse {
                $user = User::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->access_version > 0 && Hash::check($data['password'], $user->password), 401, 'Credentials changed. Sign in again.');
                if ($user->must_change_password && ($user->temporary_password_expires_at === null
                    || $user->temporary_password_expires_at->lessThanOrEqualTo(now('UTC')))) {
                    $this->audit($user, 'employee.login', 'REFUSED', 'temporary_access_expired');

                    return response()->json(['code' => 'TEMPORARY_ACCESS_EXPIRED', 'message' => 'Request new access from an authorized administrator.'], 403);
                }
                if (! $user->must_change_password && CustomerProfile::where('user_id', $user->id)->exists()) {
                    $this->audit($user, 'employee.login', 'REFUSED', 'customer_code_required');

                    return response()->json(['code' => 'CUSTOMER_CODE_REQUIRED', 'message' => 'Utilisez votre code client pour vous connecter.'], 403);
                }
                if (! $user->must_change_password) {
                    app(AccountReactivation::class)->consumeApproved($user);
                }
                Auth::guard('web')->login($user);
                $request->session()->regenerate();
                $request->session()->put('employee_access_version', $user->access_version);
                $restricted = $user->inactivity_suspended_at !== null;
                $request->session()->put('inactivity_version', $user->inactivity_version);
                $request->session()->put('inactivity_restricted', $restricted);
                if ($restricted) {
                    $this->audit($user, 'employee.login', 'REFUSED', 'inactivity_restricted_session');

                    return response()->json(['code' => 'INACTIVITY_SUSPENDED',
                        'data' => ['canRequestReactivation' => ! $user->must_change_password,
                            'mustChangePassword' => $user->must_change_password, 'requiresFreshLoginAfterApproval' => true]], 403);
                }
                $this->audit($user, 'employee.login', 'SUCCESS', $user->must_change_password ? 'restricted_session_opened' : 'session_opened');

                return response()->json(['data' => ['userId' => $user->id, 'mustChangePassword' => $user->must_change_password]]);
            }, 3);
        } catch (Throwable $exception) {
            $this->closeSession($request);

            throw $exception;
        }
    }

    public function replacePassword(ReplaceTemporaryPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $request): void {
            $user = User::whereKey($request->user()->getAuthIdentifier())->lockForUpdate()->firstOrFail();
            abort_unless($request->session()->get('employee_access_version') === $user->access_version, 401, 'Sign in again.');
            abort_unless($user->must_change_password, 409, 'Temporary access has already been replaced.');
            abort_if($user->temporary_password_expires_at === null || $user->temporary_password_expires_at->lessThanOrEqualTo(now('UTC')), 403, 'Temporary access has expired.');
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            if (Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a password different from the temporary password.']);
            }
            $user->password = Hash::make($data['password']);
            $user->must_change_password = false;
            $user->temporary_password_expires_at = null;
            $user->access_version++;
            $user->setRememberToken(Str::random(60));
            $user->save();
            $this->audit($user, 'employee.password.replaced', 'SUCCESS', 'temporary_access_invalidated');
        });
        $this->closeSession($request);

        return response()->json(['code' => 'PASSWORD_UPDATED_LOGIN_REQUIRED', 'data' => ['mustChangePassword' => false, 'requiresLogin' => true]]);
    }

    private function closeSession(Request $request): void
    {
        try {
            Auth::guard('web')->logout();
        } finally {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function audit(?User $user, string $action, string $result, string $reason): void
    {
        $event = AuditEvent::create([
            'actor_id' => $result === 'SUCCESS' ? $user?->id : null,
            'request_id' => (string) Str::uuid(), 'action' => $action,
            'object_type' => 'user', 'object_id' => $user === null ? null : (string) $user->id,
            'result' => $result, 'reason' => $reason, 'changes' => null, 'occurred_at' => now('UTC'),
        ]);
        if ($action === 'employee.login' && $result === 'SUCCESS' && $user !== null) {
            if ($user->last_login_at === null || $user->last_login_at->lessThan($event->occurred_at)) {
                $user->last_login_at = $event->occurred_at;
                $user->save();
            }
        }
    }
}
