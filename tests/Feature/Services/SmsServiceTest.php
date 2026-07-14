<?php

use App\Models\Company;
use App\Services\Sms\SmsService;

it('reflects the default provider and notifications_enabled on first lookup for a company', function (): void {
    // Regression: Eloquent's create() doesn't reflect DB column defaults onto
    // the in-memory model firstOrCreate() returns, so before this fix the
    // very first settingsFor() call for a company (no row yet) returned
    // notifications_enabled=null — silently dropping that company's first
    // customer notification.
    $company = Company::factory()->create();

    $settings = app(SmsService::class)->settingsFor($company);

    expect($settings->notifications_enabled)->toBeTrue()
        ->and($settings->provider)->toBe('log')
        ->and($settings->quiet_hours_start)->not->toBeNull()
        ->and($settings->quiet_hours_end)->not->toBeNull();
});

it('returns the same settings on a second lookup once the row already exists', function (): void {
    $company = Company::factory()->create();
    $sms = app(SmsService::class);

    $first = $sms->settingsFor($company);
    $second = $sms->settingsFor($company);

    expect($second->id)->toBe($first->id)
        ->and($second->notifications_enabled)->toBeTrue();
});
