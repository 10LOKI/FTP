<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('customers:send-approval-notifications')->everyMinute()->withoutOverlapping();

Schedule::command('employees:send-access')->everyMinute()->withoutOverlapping();

Schedule::command('notifications:deliver')->everyMinute()->withoutOverlapping();

Schedule::command('accounts:suspend-inactive')->everyMinute()->withoutOverlapping();
