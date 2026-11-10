<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->has()) {
            if ($request->user()?->is_super_admin) {
                return redirect()->route('platform.tenants');
            }

            abort(403, 'No tenant access.');
        }

        return $next($request);
    }
}
