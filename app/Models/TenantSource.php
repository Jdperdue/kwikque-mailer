<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantSource extends Model
{
    use BelongsToTenant;

    protected $guarded = ['tenant_id'];

    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            // Encrypted with APP_KEY. Never regenerate APP_KEY.
            'settings' => 'encrypted:array',
            'last_run_at' => 'datetime',
        ];
    }
}
