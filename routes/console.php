<?php

use App\Notifications\PlatformAlert;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('loans:flag-arrears')->dailyAt('01:00');
Schedule::command('savings:mature-target-accounts')->dailyAt('01:30');
Schedule::command('savings:mature-fixed-deposits')->dailyAt('01:35');
Schedule::command('kyc:purge-expired')->dailyAt('02:00');
Schedule::command('ledger:verify-balances')->dailyAt('02:30');
Schedule::command('billing:run')->dailyAt('03:00')
    ->onFailure(fn () => PlatformAlert::toSuperAdmins('Billing run failed', 'The daily billing:run command failed. Invoices, reminders and suspensions did not go out — check the logs and run it again.'));
Schedule::command('platform:digest')->dailyAt('07:00');
Schedule::command('exports:prune')->dailyAt('04:00');
