<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\MissingTenantContext;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for every queued job that touches tenant data.
 * Captures tenant_id at dispatch time (or fails loudly) and restores the
 * tenant context before process() runs. Subclasses implement process() and
 * must call parent::__construct() if they define a constructor.
 */
abstract class TenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tenantId;

    public function __construct(?int $tenantId = null)
    {
        $this->tenantId = $tenantId ?? app(TenantContext::class)->requireId('dispatch a tenant job');
    }

    final public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId)
            ?? throw new MissingTenantContext("Tenant {$this->tenantId} no longer exists for ".static::class.'.');

        app(TenantContext::class)->run($tenant, fn () => app()->call([$this, 'process']));
    }

    abstract public function process(): void;
}
