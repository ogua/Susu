<?php

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementLevel;
use App\Filament\SuperAdmin\Pages\PlatformSettingsPage;
use App\Filament\SuperAdmin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Notifications\DatabaseNotification;

beforeEach(function (): void {
    seedRoles();
    $this->superAdmin = User::factory()->superAdmin()->create();
});

it('saves platform settings that override the config defaults', function (): void {
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    livewire(PlatformSettingsPage::class)
        ->fillForm([
            'license_price' => '650.00',
            'license_duration_days' => 180,
            'billing_grace_days' => 10,
            'billing_suspend_after_days' => 14,
            'support_email' => 'help@susuapp.test',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(config('license.price'))->toBe(650_00)
        ->and(config('billing.grace_days'))->toBe(10)
        ->and(config('platform.support_email'))->toBe('help@susuapp.test');

    config(['license.price' => 1]);
    app(PlatformSettings::class)->apply();

    expect(config('license.price'))->toBe(650_00);
});

it('publishes an announcement and optionally notifies the audience', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $admin = User::factory()->companyAdmin($company)->create();
    $agent = User::factory()->fieldAgent($branch)->create();

    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();

    livewire(CreateAnnouncement::class)
        ->fillForm([
            'title' => 'Maintenance tonight',
            'body' => 'The system will be unavailable from 23:00 to 23:30.',
            'level' => AnnouncementLevel::Warning->value,
            'audience' => AnnouncementAudience::CompanyAdmins->value,
            'starts_at' => now()->subMinute(),
            'notify_now' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Announcement::where('title', 'Maintenance tonight')->exists())->toBeTrue()
        ->and(DatabaseNotification::where('notifiable_id', $admin->id)->exists())->toBeTrue()
        ->and(DatabaseNotification::where('notifiable_id', $agent->id)->exists())->toBeFalse();
});

it('serves running announcements to the right roles over the API', function (): void {
    $branch = Branch::factory()->create();
    $agent = User::factory()->fieldAgent($branch)->create();
    $customer = User::factory()->customerUser($branch->company)->create();

    $forAll = Announcement::factory()->create(['title' => 'For everyone', 'level' => AnnouncementLevel::Info]);
    Announcement::factory()->create(['title' => 'Admins only', 'audience' => AnnouncementAudience::CompanyAdmins]);
    Announcement::factory()->create(['title' => 'Already over', 'ends_at' => now()->subMinute()]);
    Announcement::factory()->create(['title' => 'Not yet', 'starts_at' => now()->addDay()]);
    $critical = Announcement::factory()->create(['title' => 'Urgent', 'level' => AnnouncementLevel::Critical]);

    $this->actingAs($agent, 'sanctum')->getJson('/api/v1/announcements')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $critical->id)
        ->assertJsonPath('data.1.id', $forAll->id);

    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/announcements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('shows running announcements as a banner in the admin panel', function (): void {
    $branch = Branch::factory()->create();
    $manager = User::factory()->branchManager($branch)->create();
    Announcement::factory()->create(['title' => 'New loan reports are live']);

    $this->actingAs($manager)->get('/admin/'.$branch->slug)->assertOk()->assertSee('New loan reports are live');
});
