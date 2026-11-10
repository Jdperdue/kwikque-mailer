<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:operator') means operator or admin. */
class RequireRole
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $required = TenantRole::from($role);
        $actual = $request->user()?->roleIn($this->context->current());

        abort_unless($actual !== null && $actual->atLeast($required), 403);

        return $next($request);
    }
}
