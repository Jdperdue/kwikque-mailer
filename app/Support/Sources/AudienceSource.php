<?php

declare(strict_types=1);

namespace App\Support\Sources;

use App\Models\AudienceGroup;
use App\Models\Tenant;
use App\Support\Audience\RecipientData;

interface AudienceSource
{
    /** Short stable identifier stored in recipients.source (e.g. csv, manual, legacy). */
    public function name(): string;

    /** @return iterable<RecipientData> */
    public function recipients(Tenant $tenant, AudienceGroup $group): iterable;
}
