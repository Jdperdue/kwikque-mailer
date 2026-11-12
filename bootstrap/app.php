<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\RequireTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ResolveTenant::class]);
        // Tenant context must exist before implicit route-model binding touches tenant-owned models.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
        // RFC 8058 one-click unsubscribe is POSTed by mail providers without a CSRF token; the signed token is the credential.
        $middleware->validateCsrfTokens(except: ['u/*']);
        $middleware->alias([
            'tenant.required' => RequireTenant::class,
            'role' => RequireRole::class,
            'super' => RequireSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
