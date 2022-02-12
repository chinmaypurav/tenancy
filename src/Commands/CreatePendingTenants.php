<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;

class CreatePendingTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:pending {--count= The number of tenant to be in a pending state}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy tenants until the pending count is achieved.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Deploying pendgin tenants.');

        $pendingObjectifCount = (int)config('tenancy.pending.count');

        $pendingCurrentCount = $this->getPendingTenantCount();

        $deployedCount = 0;
        while ($pendingCurrentCount < $pendingObjectifCount) {
            tenancy()->model()::createPending();
            // We update the number of pending tenants every time with a query to get a live count.
            // this prevents to deploy too many tenants if pending tenants are being created simultaneous somewhere else
            // during the runtime of this command.
            $pendingCurrentCount = $this->getPendingTenantCount();
            $deployedCount++;
        }

        $this->info("$deployedCount tenants deployed, $pendingObjectifCount tenant(s) are ready to be used.");

        return 1;
    }

    /**
     * Calculates the number of pending tenants currently deployed
     * @return int
     */
    private function getPendingTenantCount(): int
    {
        return tenancy()
            ->query()
            ->onlyPending()
            ->count();
    }
}
