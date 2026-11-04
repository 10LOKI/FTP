<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditCustomerApprovalDecision
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('approval_request_id', $requestId);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof ValidationException ? 422
                : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
            $this->recordFailure($request, $requestId, $status);

            throw $exception;
        }

        if ($response->getStatusCode() >= 400) {
            $this->recordFailure($request, $requestId, $response->getStatusCode());
        }

        return $response;
    }

    private function recordFailure(Request $request, string $requestId, int $status): void
    {
        $profile = $request->route('customerProfile');

        AuditEvent::create([
            'actor_id' => $request->user()?->getAuthIdentifier(),
            'request_id' => $requestId,
            'action' => 'customer.approval_decision',
            'object_type' => 'customer_profile',
            'object_id' => $profile instanceof CustomerProfile ? $profile->id : null,
            'result' => $status >= 500 ? 'FAILED' : 'REFUSED',
            'reason' => match ($status) {
                409 => 'decision_conflict',
                422 => 'validation_failed',
                default => $status >= 500 ? 'decision_failed' : 'decision_denied',
            },
            'changes' => null,
            'occurred_at' => now('UTC'),
        ]);
    }
}
