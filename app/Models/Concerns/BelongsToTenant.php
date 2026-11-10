<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\MissingTenantContext;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every tenant-owned model: global scope plus tenant_id on create.
 * Do not bypass with withoutGlobalScopes() outside the super-admin area or an
 * explicit, commented case.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $contextId = app(TenantContext::class)->requireId('create tenant-owned data');

            if ($model->tenant_id === null) {
                $model->tenant_id = $contextId;
            } elseif ((int) $model->tenant_id !== $contextId) {
                throw new MissingTenantContext("Refusing to create a record for tenant {$model->tenant_id} while in tenant {$contextId}.");
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
