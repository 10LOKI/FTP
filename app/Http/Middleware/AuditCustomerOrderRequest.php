<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditCustomerOrderRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('order_request_id', $requestId);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $this->record($request, $requestId, $status);
            throw $exception;
        }

        if ($response->getStatusCode() >= 400 || ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            $this->record($request, $requestId, $response->getStatusCode());
        }

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function record(Request $request, string $requestId, int $status): void
    {
        AuditEvent::create([
            'actor_id' => $request->user()?->getAuthIdentifier(), 'request_id' => $requestId,
            'action' => ($request->isMethod('GET') || $request->isMethod('HEAD')) ? 'order.view' : 'order.submit',
            'object_type' => 'customer_order', 'object_id' => null,
            'result' => $status >= 500 ? 'FAILED' : ($status >= 400 ? 'REFUSED' : 'SUCCESS'),
            'reason' => $status >= 400 ? 'http_'.$status : null,
            'changes' => null, 'occurred_at' => now('UTC'),
        ]);
    }
}
