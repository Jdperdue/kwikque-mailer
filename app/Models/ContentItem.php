<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ContentItem extends Model
{
    use BelongsToTenant;

    /** A content item is an auction for Rene Bates; other tenants may use course or event. */
    public const TYPES = ['auction', 'course', 'event'];

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'synced_at' => 'datetime',
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
