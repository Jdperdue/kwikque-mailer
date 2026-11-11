<?php

declare(strict_types=1);

namespace App\Support\Sources;

use App\Models\Tenant;

interface ContentSource
{
    /**
     * Featured items for a mailer (auctions, courses, events), as arrays matching
     * the content_items columns. Implementations arrive with Spectrum (Phase 5);
     * until then content is entered through the manual form.
     *
     * @return iterable<array<string, mixed>>
     */
    public function activeItems(Tenant $tenant): iterable;
}
