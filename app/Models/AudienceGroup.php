<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AudienceGroup extends Model
{
    use BelongsToTenant;

    protected $guarded = ['tenant_id'];

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(Recipient::class, 'recipient_group')
            ->withPivotValue('tenant_id', app(TenantContext::class)->requireId('relate group recipients'))
            ->withTimestamps();
    }
}
