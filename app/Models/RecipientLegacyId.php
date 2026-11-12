<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientLegacyId extends Model
{
    use BelongsToTenant;

    /** Legacy id column header => kind. */
    public const KINDS = ['bidder_id' => 'bidder', 'client_id' => 'client', 'email_id' => 'email'];

    protected $guarded = ['tenant_id'];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }
}
