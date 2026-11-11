<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }
}
