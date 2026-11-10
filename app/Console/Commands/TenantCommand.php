<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

/** Base for console commands that touch tenant data: requires --tenant=<slug> and sets the context. */
abstract class TenantCommand extends Command
{
    public function __construct()
    {
        parent::__construct();

        $this->getDefinition()->addOption(
            new InputOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant slug (required)')
        );
    }

    final public function handle(TenantContext $context): int
    {
        $slug = $this->option('tenant');

        if (! is_string($slug) || $slug === '') {
            $this->error('The --tenant=<slug> option is required.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Unknown tenant '{$slug}'.");

            return self::FAILURE;
        }

        return (int) $context->run($tenant, fn () => $this->handleTenant());
    }

    abstract protected function handleTenant(): int;
}
