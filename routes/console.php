<?php

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
