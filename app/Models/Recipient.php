<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipient extends Model
{
    use BelongsToTenant;

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return [
            'subscribed' => 'boolean',
            'unsubscribed_at' => 'datetime',
            'suppressed_at' => 'datetime',
            'consent_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(AudienceGroup::class, 'recipient_group')
            ->withPivotValue('tenant_id', app(TenantContext::class)->requireId('relate recipient groups'))
            ->withTimestamps();
    }

    public function legacyIds(): HasMany
    {
        return $this->hasMany(RecipientLegacyId::class);
    }

    public function scopeMailable($query)
    {
        return $query->where('recipients.subscribed', true)
            ->whereNull('recipients.unsubscribed_at')
            ->whereNull('recipients.suppressed_at');
    }

    public function isOptedOut(): bool
    {
        return $this->unsubscribed_at !== null || $this->suppressed_at !== null;
    }

    /** Eligible for mailing as far as this tenant's own flags go (platform list is checked at send time). */
    public function isMailable(): bool
    {
        return $this->subscribed && ! $this->isOptedOut();
    }
}
