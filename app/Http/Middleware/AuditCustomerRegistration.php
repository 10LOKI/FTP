<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditCustomerRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('registration_request_id', $requestId);

        try {
            $response = $next($request);
        } catch (ValidationException $exception) {
            AuditEvent::recordRegistration($requestId, 'REFUSED', reason: 'validation_failed');

            throw $exception;
        } catch (Throwable $exception) {
            AuditEvent::recordRegistration($requestId, 'FAILED', reason: 'registration_failed');

            throw $exception;
        }

        if ($response->getStatusCode() >= 400) {
            $failed = $response->getStatusCode() >= 500;
            AuditEvent::recordRegistration($requestId, $failed ? 'FAILED' : 'REFUSED', reason: $failed ? 'registration_failed' : 'validation_failed');
        }

        return $response;
    }
}
