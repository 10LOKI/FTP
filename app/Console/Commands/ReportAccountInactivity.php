<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountInactivityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ReportAccountInactivity extends Command
{
    protected $signature = 'accounts:inactivity-report';

    protected $description = 'Read-only four-calendar-month eligibility report; does not suspend accounts';

    public function handle(AccountInactivityPolicy $policy): int
    {
        $checked = 0;
        $due = 0;
        $missing = 0;
        $now = CarbonImmutable::now('UTC');
        User::orderBy('id')->chunkById(100, function (Collection $users) use ($policy, $now, &$checked, &$due, &$missing): void {
            foreach ($users as $user) {
                $checked++;
                $assessment = $policy->assess($user, $now);
                if ($assessment['status'] === 'DUE') {
                    $due++;
                    $this->line('User '.$user->id.': DUE at '.$assessment['deadline']->toIso8601String().' via '.$assessment['source']);
                } elseif ($assessment['status'] === 'MISSING_BASELINE') {
                    $missing++;
                    $this->warn('User '.$user->id.': skipped; approval/activity baseline is missing.');
                }
            }
        });
        $this->info('Checked: '.$checked.'; due: '.$due.'; missing baseline: '.$missing.'. No accounts changed.');

        return self::SUCCESS;
    }
}
