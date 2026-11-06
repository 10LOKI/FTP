<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\InactivitySuspension;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $request->user();
        if ($authenticated === null || $request->routeIs('account.logout')) {
            return $next($request);
        }
        $user = User::find($authenticated->getAuthIdentifier());
        if ($user === null) {
            return $this->invalidate($request);
        }
        if ($user->must_change_password && ! $request->routeIs('employee.password.replace')) {
            return response()->json(['code' => 'PASSWORD_CHANGE_REQUIRED', 'message' => 'Change your temporary password before business access.'], 403);
        }
        if ($user->access_version > 0 && (! $request->hasSession()
            || $request->session()->get('employee_access_version') !== $user->access_version)) {
            return $this->invalidate($request);
        }
        if ($user->must_change_password && ($user->temporary_password_expires_at === null
            || $user->temporary_password_expires_at->lessThanOrEqualTo(now('UTC')))) {
            return response()->json(['code' => 'TEMPORARY_ACCESS_EXPIRED', 'message' => 'Request new access from an authorized administrator.'], 403);
        }

        app(InactivitySuspension::class)->check($user);
        $user->refresh();
        if ($user->inactivity_suspended_at !== null) {
            if ($request->hasSession()) {
                $request->session()->put('inactivity_restricted', true);
                $request->session()->put('inactivity_version', $user->inactivity_version);
            }
            if (! $request->routeIs('account.reactivation.*', 'employee.password.replace')) {
                return response()->json(['code' => 'INACTIVITY_SUSPENDED', 'message' => 'Request administrative reactivation.'], 403);
            }
        } elseif (! $request->routeIs('account.reactivation.*')) {
            $hasSession = $request->hasSession();
            $staleSession = $hasSession
                && ((bool) $request->session()->get('inactivity_restricted', false)
                    || (int) $request->session()->get('inactivity_version', 0) !== $user->inactivity_version);
            if ($staleSession || (! $hasSession && $user->inactivity_version > 0)) {
                return $this->invalidate($request);
            }
        }

        return $next($request);
    }

    private function invalidate(Request $request): Response
    {
        try {
            Auth::guard('web')->logout();
        } finally {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->json(['code' => 'SESSION_INVALIDATED', 'message' => 'Sign in again.'], 401);
    }
}
