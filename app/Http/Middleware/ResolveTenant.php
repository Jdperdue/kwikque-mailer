<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sets the tenant context for a request from the session, validated against membership every time. */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $tenants = $user->accessibleTenants();
            $tenant = $tenants->firstWhere('id', $request->session()->get('tenant_id'));

            if ($tenant === null && ! $user->is_super_admin) {
                $tenant = $tenants->first();
            }

            if ($tenant !== null) {
                $this->context->set($tenant);
                $request->session()->put('tenant_id', $tenant->getKey());
            } else {
                $request->session()->forget('tenant_id');
            }
        }

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
