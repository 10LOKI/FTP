<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerLoginRequest;
use App\Http\Resources\CustomerAccountResource;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AccountReactivation;
use App\Services\InactivitySuspension;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Throwable;

class CustomerSessionController extends Controller
{
    public function store(CustomerLoginRequest $request): CustomerAccountResource|JsonResponse
    {
        abort_if(Auth::guard('web')->check(), 409, 'Sign out before opening another customer session.');
        $data = $request->validated();
        $profile = CustomerProfile::with('user')->where('customer_code', $data['identifier'])->first();
        $matchedCode = $profile !== null;

        if ($profile === null && filter_var($data['identifier'], FILTER_VALIDATE_EMAIL)) {
            $profile = CustomerProfile::with('user')->whereHas('user', function (Builder $query) use ($data): void {
                $query->where('email', $data['identifier']);
            })->first();
        }

        $validPassword = (new Timebox)->call(
            fn (): bool => $profile !== null && Hash::check($data['password'], $profile->user->password),
            200000,
        );

        if (! $validPassword) {
            $this->recordLogin(null, 'REFUSED', 'invalid_credentials');

            return response()->json(['code' => 'INVALID_CREDENTIALS', 'message' => 'Identifiants invalides.'], 401);
        }

        if ($profile->user->must_change_password) {
            $this->recordLogin($profile, 'REFUSED', 'password_change_required');

            return response()->json(['code' => 'PASSWORD_CHANGE_REQUIRED', 'message' => 'Use the temporary access flow to replace your password.'], 403);
        }

        if ($profile->approval !== 'APPROUVE') {
            $this->recordLogin($profile, 'REFUSED', 'account_not_approved');

            return response()->json(match ($profile->approval) {
                'EN_ATTENTE' => ['code' => 'ACCOUNT_PENDING', 'message' => 'Votre demande est en attente d’approbation.'],
                'REFUSE' => ['code' => 'ACCOUNT_REFUSED', 'message' => 'Votre demande de compte a été refusée.'],
                default => ['code' => 'ACCOUNT_RESTRICTED', 'message' => 'L’accès à ce compte est indisponible.'],
            }, 403);
        }

        if (! $matchedCode) {
            $this->recordLogin($profile, 'REFUSED', 'customer_code_required');

            return response()->json(['code' => 'CUSTOMER_CODE_REQUIRED', 'message' => 'Utilisez votre code client pour vous connecter.'], 403);
        }

        try {
            app(InactivitySuspension::class)->check($profile->user);

            return DB::transaction(function () use ($profile, $request, $data): CustomerAccountResource|JsonResponse {
                $user = User::whereKey($profile->user_id)->lockForUpdate()->firstOrFail();
                $current = CustomerProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
                abort_unless(Hash::check($data['password'], $user->password), 401, 'Credentials changed. Sign in again.');
                abort_if($user->must_change_password || $current->approval !== 'APPROUVE', 403, 'Account access has changed.');
                app(AccountReactivation::class)->consumeApproved($user);
                $current->setRelation('user', $user);
                Auth::guard('web')->login($user);
                $request->session()->regenerate();
                if ($user->access_version > 0) {
                    $request->session()->put('employee_access_version', $user->access_version);
                }
                $restricted = $user->inactivity_suspended_at !== null;
                $request->session()->put('inactivity_version', $user->inactivity_version);
                $request->session()->put('inactivity_restricted', $restricted);
                if ($restricted) {
                    $this->recordLogin($current, 'REFUSED', 'inactivity_restricted_session');

                    return response()->json(['code' => 'INACTIVITY_SUSPENDED',
                        'data' => ['canRequestReactivation' => true, 'requiresFreshLoginAfterApproval' => true]], 403);
                }
                $this->recordLogin($current, 'SUCCESS', 'session_opened');

                return new CustomerAccountResource($current);
            }, 3);
        } catch (Throwable $exception) {
            $this->closeSession($request);

            throw $exception;
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            AuditEvent::create([
                'actor_id' => $request->user()->getAuthIdentifier(),
                'request_id' => (string) Str::uuid(),
                'action' => 'account.logout',
                'object_type' => 'user',
                'object_id' => (string) $request->user()->getAuthIdentifier(),
                'result' => 'SUCCESS',
                'reason' => 'session_closed',
                'changes' => null,
                'occurred_at' => now('UTC'),
            ]);
        } finally {
            $this->closeSession($request);
        }

        return response()->noContent();
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

    private function recordLogin(?CustomerProfile $profile, string $result, string $reason): void
    {
        DB::transaction(function () use ($profile, $result, $reason): void {
            $event = AuditEvent::create([
                'actor_id' => $result === 'SUCCESS' ? $profile?->user_id : null,
                'request_id' => (string) Str::uuid(),
                'action' => 'customer.login',
                'object_type' => 'customer_profile',
                'object_id' => $profile?->id,
                'result' => $result,
                'reason' => $reason,
                'changes' => null,
                'occurred_at' => now('UTC'),
            ]);
            if ($result === 'SUCCESS' && $profile !== null) {
                User::whereKey($profile->user_id)
                    ->where(function (Builder $query) use ($event): void {
                        $query->whereNull('last_login_at')->orWhere('last_login_at', '<', $event->occurred_at);
                    })->update(['last_login_at' => $event->occurred_at]);
            }
        });
    }
}
