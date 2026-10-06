<?php

use App\Enums\AppPlatform;
use App\Filament\SuperAdmin\Resources\AppVersions\Pages\CreateAppVersion;
use App\Filament\SuperAdmin\Resources\AppVersions\Pages\ListAppVersions;
use App\Models\AppVersion;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function (): void {
    seedRoles();
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($this->superAdmin);
    bootSuperAdminPanel();
});

it('publishes a release and the apps see it', function (): void {
    livewire(CreateAppVersion::class)
        ->fillForm([
            'platform' => AppPlatform::Android->value,
            'version' => '1.2.0',
            'version_code' => 3,
            'minimum_supported_version' => '1.0.0',
            'is_forced_update' => true,
            'store_url' => 'https://play.google.com/store/apps/details?id=com.oguaschoolz.susuapp',
            'release_notes' => 'Security fix',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(AppVersion::class, [
        'platform' => 'android',
        'version' => '1.2.0',
        'created_by' => $this->superAdmin->id,
    ]);

    $this->getJson('/api/v1/app/update-check?platform=android&version=1.1.0')
        ->assertJsonPath('data.force_update', true)
        ->assertJsonPath('data.update_message', 'Security fix');
});

it('rejects malformed versions, duplicates per platform and a floor above the release', function (): void {
    AppVersion::factory()->create(['version' => '1.2.0', 'version_code' => 3]);

    livewire(CreateAppVersion::class)
        ->fillForm([
            'platform' => AppPlatform::Android->value,
            'version' => '1.2.0',
            'version_code' => 3,
        ])
        ->call('create')
        ->assertHasFormErrors(['version' => 'unique', 'version_code' => 'unique']);

    livewire(CreateAppVersion::class)
        ->fillForm([
            'platform' => AppPlatform::Android->value,
            'version' => '1.3',
            'version_code' => 4,
        ])
        ->call('create')
        ->assertHasFormErrors(['version' => 'regex']);

    livewire(CreateAppVersion::class)
        ->fillForm([
            'platform' => AppPlatform::Android->value,
            'version' => '1.3.0',
            'version_code' => 4,
            'minimum_supported_version' => '1.4.0',
        ])
        ->call('create')
        ->assertHasFormErrors(['minimum_supported_version']);
});

it('allows the same version on another platform', function (): void {
    AppVersion::factory()->create(['version' => '1.2.0', 'version_code' => 3]);

    livewire(CreateAppVersion::class)
        ->fillForm([
            'platform' => AppPlatform::Ios->value,
            'version' => '1.2.0',
            'version_code' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('pulls a release so the previous one is served again', function (): void {
    AppVersion::factory()->create(['version' => '1.1.0', 'version_code' => 2]);
    $bad = AppVersion::factory()->create(['version' => '1.2.0', 'version_code' => 3]);

    livewire(ListAppVersions::class)
        ->callAction(TestAction::make('deactivate')->table($bad))
        ->assertNotified();

    $this->getJson('/api/v1/app/update-check?platform=android&version=1.0.0')
        ->assertJsonPath('data.latest_version', '1.1.0');
});
