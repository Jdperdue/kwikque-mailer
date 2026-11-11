<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GLOBAL on purpose (hard bounces and complaints, checked on every send for every tenant).
 * Deliberately not BelongsToTenant. Only the system writes here; there is no tenant UI.
 */
class PlatformSuppression extends Model
{
    protected $guarded = [];
}
