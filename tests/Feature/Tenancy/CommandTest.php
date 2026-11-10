<?php

declare(strict_types=1);

use App\Console\Commands\TenantCommand;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;

class ProbeTenantCommand extends TenantCommand
{
    protected $signature = 'test:tenant-probe';

    public static ?int $seen = null;

    protected function handleTenant(): int
    {
        self::$seen = app(TenantContext::class)->id();

        return self::SUCCESS;
    }
}

beforeEach(function () {
    Artisan::registerCommand(new ProbeTenantCommand);
    ProbeTenantCommand::$seen = null;
});

it('requires --tenant', function () {
    $this->artisan('test:tenant-probe')->assertFailed();

    expect(ProbeTenantCommand::$seen)->toBeNull();
});

it('rejects an unknown tenant', function () {
    $this->artisan('test:tenant-probe', ['--tenant' => 'nope'])->assertFailed();
});

it('sets the tenant context for the command and clears it afterwards', function () {
    $tenant = makeTenant(['slug' => 'probe-tenant']);

    $this->artisan('test:tenant-probe', ['--tenant' => 'probe-tenant'])->assertSuccessful();

    expect(ProbeTenantCommand::$seen)->toBe($tenant->id)
        ->and(app(TenantContext::class)->has())->toBeFalse();
});
