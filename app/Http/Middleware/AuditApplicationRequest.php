<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Services\AuditContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditApplicationRequest
{
    public function __construct(private AuditContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->reset(true);
        try {
            $response = $next($request);
            $status = (int) $request->attributes->get('audit_exception_status', $response->getStatusCode());
            if ($status >= 400) {
                $this->failure($request, $status);
            } else {
                if ($this->context->events === 0) {
                    $this->record($request, $status);
                }
            }

            $response->headers->set('X-Request-ID', $this->context->requestId);

            return $response;
        } catch (Throwable $exception) {
            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $this->failure($request, $status);
            throw $exception;
        } finally {
            $this->context->reset();
        }
    }

    private function failure(Request $request, int $status): void
    {
        $events = $this->context->failures;
        if ($events !== []) {
            foreach ($events as $attributes) {
                if (! DB::table('audit_events')->where('id', $attributes['id'])->exists()) {
                    DB::table('audit_events')->insert($attributes);
                }
            }
        } else {
            $this->record($request, $status);
        }
    }

    private function record(Request $request, int $status): void
    {
        $uri = $request->route()?->uri() ?? '';
        $action = match (true) {
            $uri === 'gestion/audit' => 'audit.page_view',
            str_contains($uri, 'audit-events/export') => $request->isMethod('HEAD') ? 'audit.export_metadata' : 'audit.export',
            str_contains($uri, 'audit-events') => 'audit.read',
            str_contains($uri, 'notification-deliveries') => $request->isMethod('POST') ? 'notification.retry_requested' : 'notification.read',
            str_contains($uri, 'catalogue') => $request->filled('reference') ? 'catalogue.search' : 'catalogue.view',
            str_contains($uri, 'products') => 'product.'.strtolower($request->method()),
            str_contains($uri, 'promotions') => 'promotion.'.strtolower($request->method()),
            str_contains($uri, 'employee'), str_contains($uri, 'temporary-password') => 'employee.request',
            default => 'application.request',
        };
        $objectType = $action === 'audit.page_view' ? 'audit_console' : (str_starts_with($action, 'audit.') ? 'audit_log'
            : (str_starts_with($action, 'product.') ? 'product' : 'request'));
        $objectId = null;
        foreach (['product' => 'product', 'promotion' => 'promotion', 'customerProfile' => 'customer_profile', 'order' => 'customer_order', 'user' => 'user', 'delivery' => 'notification_delivery'] as $parameter => $type) {
            $target = $request->route($parameter);
            if ($target !== null) {
                $objectType = $parameter === 'delivery' ? match ($request->route('kind')) {
                    'approval' => 'approval_notification', 'employee' => 'employee_access_delivery', default => 'notification_delivery',
                } : $type;
                $objectId = (string) ($target instanceof Model ? $target->getKey() : $target);
                break;
            }
        }
        AuditEvent::create([
            'actor_id' => $request->user()?->getAuthIdentifier(), 'request_id' => (string) Str::uuid(),
            'action' => $action, 'object_type' => $objectType,
            'object_id' => $objectId, 'result' => $status >= 500 ? 'FAILED' : ($status >= 400 ? 'REFUSED' : 'SUCCESS'),
            'reason' => $status >= 400 ? 'http_'.$status : null, 'changes' => null, 'occurred_at' => now('UTC'),
        ]);
    }
}
