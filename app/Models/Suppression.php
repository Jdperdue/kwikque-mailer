<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Permanent per-tenant opt-out/block record; outlives the recipient row. */
class Suppression extends Model
{
    use BelongsToTenant;

    public const REASONS = ['unsubscribe', 'manual', 'bounce', 'complaint', 'import'];

    protected $guarded = ['tenant_id'];
}
