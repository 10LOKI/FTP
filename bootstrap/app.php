<?php

use App\Http\Middleware\EnsureEmployeeSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->web(append: [EnsureEmployeeSession::class]);
        $middleware->api(append: [EnsureEmployeeSession::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureEmployeeSession::class);
        $middleware->trimStrings(except: ['original_reference', 'reference', 'references.*.reference', 'expected_reference']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
