<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\ExtendsLaravelCommand;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Concerns\ParallelCommand;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\MigratingDatabase;

class Migrate extends MigrateCommand
{
    use HasTenantOptions, DealsWithMigrations, ExtendsLaravelCommand, ParallelCommand;

    protected $description = 'Run migrations for tenant(s)';

    protected static function getTenantCommandName(): string
    {
        return 'tenants:migrate';
    }

    public function __construct(Migrator $migrator, Dispatcher $dispatcher)
    {
        parent::__construct($migrator, $dispatcher);

        $this->addOption('skip-failing', description: 'Continue execution if migration fails for a tenant');
        $this->addProcessesOption();

        $this->specifyParameters();
    }

    public function handle(): int
    {
        foreach (config('tenancy.migration_parameters') as $parameter => $value) {
            if (! $this->input->hasParameterOption($parameter)) {
                $this->input->setOption(ltrim($parameter, '-'), $value);
            }
        }

        if (! $this->confirmToProceed()) {
            return 1;
        }

        if ($this->getProcesses() > 1) {
            return $this->runConcurrently($this->getTenantChunks()->map(function ($chunk) {
                return $this->getTenants(array_values($chunk->all()));
            }));
        }

        return $this->migrateTenants($this->getTenants()) ? 0 : 1;
    }

    protected function childHandle(...$args): bool
    {
        $chunk = $args[0];

        return $this->migrateTenants($chunk);
    }

    protected function migrateTenants(LazyCollection $tenants): bool
    {
        $success = true;

        foreach ($tenants as $tenant) {
            try {
                $this->components->info("Migrating tenant {$tenant->getTenantKey()}");

                $tenant->run(function ($tenant) use (&$success) {
                    event(new MigratingDatabase($tenant));

                    // Migrate
                    if (parent::handle() !== 0) {
                        $success = false;
                    }

                    event(new DatabaseMigrated($tenant));
                });
            } catch (TenantDatabaseDoesNotExistException|QueryException $e) {
                $this->components->error("Migration failed for tenant {$tenant->getTenantKey()}: {$e->getMessage()}");
                $success = false;

                if (! $this->option('skip-failing')) {
                    throw $e;
                }
            }
        }

        return $success;
    }
}
