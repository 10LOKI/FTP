<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Services\CustomerFinance;
use Illuminate\Console\Command;

class ReviewCustomerReceivables extends Command
{
    protected $signature = 'receivables:review';

    protected $description = 'Create internal due-date reminders and latch overdue sales blocks';

    public function handle(CustomerFinance $finance): int
    {
        if (config('receivables.enabled') !== true) {
            $this->info('Receivables policy is disabled; no changes.');

            return self::SUCCESS;
        }
        $missing = 0;
        CustomerProfile::orderBy('id')->chunkById(100, function ($profiles) use ($finance, &$missing): void {
            foreach ($profiles as $profile) {
                $state = $finance->review((int) $profile->user_id);
                $missing += $state['unresolved_delivery_count'];
            }
        });
        $this->info('Internal review complete; unresolved delivered lines: '.$missing.'.');

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
