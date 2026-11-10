<?php

declare(strict_types=1);

use App\Jobs\TenantJob;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;

arch('every queued job extends TenantJob so it carries tenant context')
    ->expect('App\Jobs')
    ->classes->toExtend(TenantJob::class)
    ->ignoring(TenantJob::class);

arch('tenant-owned models use the BelongsToTenant trait')
    ->expect('App\Models')
    ->classes->toUseTrait(BelongsToTenant::class)
    ->ignoring([Tenant::class, User::class, 'App\Models\Scopes', 'App\Models\Concerns']);

arch('source files declare strict types')
    ->expect('App')
    ->toUseStrictTypes();
