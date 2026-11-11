<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\RecipientImporter;
use App\Support\Sources\CsvImportSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

class ProcessRecipientImport extends TenantJob
{
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $importRunId, ?int $tenantId = null)
    {
        parent::__construct($tenantId);
        $this->onQueue('imports');
    }

    public function process(): void
    {
        $importer = app(RecipientImporter::class);
        $run = ImportRun::findOrFail($this->importRunId);
        $group = $run->group()->firstOrFail();
        $path = Storage::disk('local')->path((string) $run->stored_path);

        $importer->import($run, new CsvImportSource($path), app(TenantContext::class)->current(), $group);
    }
}
