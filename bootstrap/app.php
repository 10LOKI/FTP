<?php

use App\Http\Middleware\AuditApplicationRequest;
use App\Http\Middleware\EnsureEmployeeSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->web(prepend: [AuditApplicationRequest::class]);
        $middleware->api(prepend: [AuditApplicationRequest::class]);
        $middleware->web(append: [EnsureEmployeeSession::class]);
        $middleware->api(append: [EnsureEmployeeSession::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureEmployeeSession::class);
        $middleware->trimStrings(except: ['original_reference', 'reference', 'references.*.reference', 'expected_reference']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request): void {
            $request->attributes->set('audit_exception_status', match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            });
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
