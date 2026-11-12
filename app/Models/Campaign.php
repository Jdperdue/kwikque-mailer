<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Campaign extends Model
{
    use BelongsToTenant;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(AudienceGroup::class, 'campaign_groups')
            ->withPivotValue('tenant_id', app(TenantContext::class)->requireId('relate campaign groups'))
            ->withTimestamps();
    }

    /** Content items in the order they appear in the message. */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class, 'campaign_items')
            ->withPivot('sort_order')
            ->withPivotValue('tenant_id', app(TenantContext::class)->requireId('relate campaign items'))
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * Reasons this campaign cannot be scheduled yet (empty means it can).
     * Compliance: every message carries the postal address and a from address, so a tenant without them cannot schedule.
     *
     * @return list<string>
     */
    public function scheduleProblems(Tenant $tenant): array
    {
        $problems = [];

        if (trim((string) $this->subject) === '') {
            $problems[] = 'Add a subject.';
        }
        if ($this->items()->count() === 0) {
            $problems[] = 'Add at least one content item.';
        }
        if ($this->groups()->count() === 0) {
            $problems[] = 'Choose at least one audience group.';
        }
        if ($this->scheduled_at === null) {
            $problems[] = 'Choose a send time.';
        } elseif ($this->scheduled_at->isPast()) {
            $problems[] = 'The send time must be in the future.';
        }
        if (trim((string) $tenant->from_address) === '') {
            $problems[] = 'Set the from address in Settings.';
        }
        if (trim((string) $tenant->postal_address) === '') {
            $problems[] = 'Set the postal address in Settings (required in every message footer).';
        }

        return $problems;
    }
}
