<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AudienceGroup;
use App\Models\ImportRun;
use App\Support\Sources\PasteListSource;
use App\Support\Tenancy\TenantContext;

/** Manual add and paste-a-list go through the same importer, so opt-outs are protected identically. */
class ManualAudience
{
    public function __construct(private readonly RecipientImporter $importer, private readonly TenantContext $context) {}

    public function addFromText(AudienceGroup $group, string $text, string $label): ImportRun
    {
        $run = ImportRun::create([
            'user_id' => auth()->id(),
            'audience_group_id' => $group->id,
            'source' => $label,
            'filename' => null,
        ]);

        return $this->importer->import($run, new PasteListSource($text, $label), $this->context->current(), $group);
    }
}
