<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\SuppressionImporter;
use Illuminate\Support\Facades\Storage;

class ProcessSuppressionImport extends TenantJob
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
        $run = ImportRun::findOrFail($this->importRunId);
        $path = Storage::disk('local')->path((string) $run->stored_path);

        app(SuppressionImporter::class)->apply($run, $path, (string) ($run->options['reason'] ?? 'manual'));
    }
}
