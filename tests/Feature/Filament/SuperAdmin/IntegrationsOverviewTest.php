<?php

use App\Filament\SuperAdmin\Pages\IntegrationsOverview;
use App\Models\Company;
use App\Models\CompanySmsSetting;
use App\Models\NotificationLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

beforeEach(function (): void {
    seedRoles();
    $this->actingAs(User::factory()->superAdmin()->create());
    bootSuperAdminPanel();
});

it('flags companies whose SMS is not connected and counts recent failures', function (): void {
    $connected = Company::factory()->create();
    CompanySmsSetting::create(['company_id' => $connected->id, 'provider' => 'arkesel', 'sender_id' => 'OGUA', 'api_key' => 'secret', 'notifications_enabled' => true, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '07:00']);
    $unconnected = Company::factory()->create();

    NotificationLog::create(['company_id' => $connected->id, 'channel' => 'sms', 'recipient' => '+233240000001', 'body' => 'Receipt A', 'status' => 'failed']);
    NotificationLog::create(['company_id' => $connected->id, 'channel' => 'sms', 'recipient' => '+233240000002', 'body' => 'Receipt B', 'status' => 'sent']);

    livewire(IntegrationsOverview::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$connected, $unconnected])
        ->assertDontSee('secret')
        ->filterTable('sms_not_connected')
        ->assertCanSeeTableRecords([$unconnected])
        ->assertCanNotSeeTableRecords([$connected]);

    livewire(IntegrationsOverview::class)
        ->filterTable('sms_failures')
        ->assertCanSeeTableRecords([$connected])
        ->assertActionVisible(TestAction::make('failedSms')->table($connected));

    livewire(IntegrationsOverview::class)
        ->assertActionHidden(TestAction::make('failedSms')->table($unconnected));

    expect(view('filament.super-admin.failed-sms', ['logs' => NotificationLog::where('status', 'failed')->get()])->render())
        ->toContain('Receipt A')
        ->not->toContain('Receipt B');
});
