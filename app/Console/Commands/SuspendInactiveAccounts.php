<?php

namespace App\Console\Commands;

use App\Services\InactivitySuspension;
use Illuminate\Console\Command;

class SuspendInactiveAccounts extends Command
{
    protected $signature = 'accounts:suspend-inactive {--dry-run}';

    protected $description = 'Suspend due accounts while preserving the last usable administrator';

    public function handle(InactivitySuspension $suspension): int
    {
        if ($this->option('dry-run')) {
            return $this->call('accounts:inactivity-report');
        }
        $counts = $suspension->run();
        $this->info('Suspended: '.$counts['suspended'].'; protected administrators: '.$counts['protected'].'; missing baseline: '.$counts['missing'].'.');

        return self::SUCCESS;
    }
}
