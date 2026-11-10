<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Fails closed: querying tenant-owned data with no tenant in context throws. */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = app(TenantContext::class)->requireId('query tenant-owned data');

        $builder->where($model->qualifyColumn('tenant_id'), $id);
    }
}
