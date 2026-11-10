<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\MissingTenantContext;
use App\Models\Tenant;
use Closure;

/**
 * Holds the tenant for the current request, job or command.
 * Bound with scoped() so it never leaks between requests/jobs in long-lived workers.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function current(): Tenant
    {
        return $this->tenant ?? throw MissingTenantContext::for('read the current tenant');
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function requireId(string $what): int
    {
        return $this->tenant?->getKey() ?? throw MissingTenantContext::for($what);
    }

    /** Run a callback inside a tenant, restoring the previous context afterwards. */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
