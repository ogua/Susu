<?php

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use App\Services\AppUpdatePolicy;

function updateCheck(string $platform = 'android', ?string $version = '1.0.0'): array
{
    return test()->getJson(route('api.v1.app.update-check', array_filter([
        'platform' => $platform,
        'version' => $version,
    ])))->assertOk()->json('data');
}

it('is reachable without a token and prompts nothing when no release is published', function (): void {
    $this->getJson('/api/v1/app/update-check?platform=android&version=1.0.0')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'latest_version', 'minimum_version', 'update_available', 'force_update',
            'update_message', 'store_url_android', 'store_url_ios', 'download_url', 'platform',
        ]])
        ->assertJsonPath('data.latest_version', '0.0.0')
        ->assertJsonPath('data.force_update', false)
        ->assertJsonPath('data.platform', 'android');
});

it('serves the active release with the highest version code for the platform only', function (): void {
    AppVersion::factory()->create(['version' => '1.1.0', 'version_code' => 2]);
    AppVersion::factory()->create(['version' => '1.3.0', 'version_code' => 4, 'release_notes' => 'Faster sync']);
    AppVersion::factory()->inactive()->create(['version' => '1.4.0', 'version_code' => 5]);
    AppVersion::factory()->create(['platform' => AppPlatform::Ios, 'version' => '2.0.0', 'version_code' => 9]);

    expect(updateCheck())
        ->latest_version->toBe('1.3.0')
        ->update_available->toBeTrue()
        ->force_update->toBeFalse()
        ->minimum_version->toBe('0.0.0')
        ->update_message->toBe('Faster sync');
});

it('forces only devices older than a forced release', function (): void {
    AppVersion::factory()->forced()->create(['version' => '1.2.0', 'version_code' => 3]);

    expect(updateCheck(version: '1.1.9'))->force_update->toBeTrue()->minimum_version->toBe('1.2.0')
        ->and(updateCheck(version: '1.2.0'))->force_update->toBeFalse()->update_available->toBeFalse();
});

it('keeps an older forced release as the floor after a newer unforced one', function (): void {
    AppVersion::factory()->forced()->create(['version' => '1.2.0', 'version_code' => 3]);
    AppVersion::factory()->create(['version' => '1.3.0', 'version_code' => 4]);

    expect(updateCheck(version: '1.1.0'))->force_update->toBeTrue()->latest_version->toBe('1.3.0')
        ->and(updateCheck(version: '1.2.5'))->force_update->toBeFalse()->update_available->toBeTrue();
});

it('blocks devices below the minimum supported version without the force flag', function (): void {
    AppVersion::factory()->create(['version' => '1.5.0', 'version_code' => 6, 'minimum_supported_version' => '1.3.0']);

    expect(updateCheck(version: '1.2.0'))->force_update->toBeTrue()->minimum_version->toBe('1.3.0')
        ->and(updateCheck(version: '1.3.0'))->force_update->toBeFalse();
});

it('leaves the version math to the client when the caller version is unknown', function (): void {
    AppVersion::factory()->forced()->create(['version' => '1.2.0', 'version_code' => 3]);

    expect(updateCheck(version: 'dev'))
        ->force_update->toBeFalse()
        ->update_available->toBeFalse()
        ->minimum_version->toBe('1.2.0');
});

it('falls back to the configured store url when a release has none', function (): void {
    config(['app_updates.download_url_desktop' => 'https://downloads.example.com/susu.msi']);
    AppVersion::factory()->create(['platform' => AppPlatform::Desktop, 'version' => '1.1.0', 'version_code' => 2, 'store_url' => null]);

    expect(updateCheck('desktop', '1.0.0'))
        ->download_url->toBe('https://downloads.example.com/susu.msi')
        ->update_available->toBeTrue()
        ->platform->toBe('desktop');
});

it('ignores an unknown platform', function (): void {
    AppVersion::factory()->forced()->create(['version' => '9.0.0', 'version_code' => 90]);

    expect(updateCheck('windows-phone'))->platform->toBeNull()->force_update->toBeFalse();
});

it('refreshes the cached policy when a release is published or pulled', function (): void {
    expect(updateCheck())->latest_version->toBe('0.0.0');

    $release = AppVersion::factory()->create(['version' => '1.1.0', 'version_code' => 2]);
    expect(updateCheck())->latest_version->toBe('1.1.0');

    $release->update(['is_active' => false]);
    expect(updateCheck())->latest_version->toBe('0.0.0');
});

it('normalizes loose version strings', function (?string $input, ?string $expected): void {
    expect(AppUpdatePolicy::normalize($input))->toBe($expected);
})->with([
    ['1.2.3', '1.2.3'],
    ['1.2', '1.2.0'],
    ['v2', '2.0.0'],
    ['1.0-SNAPSHOT', '1.0.0'],
    ['1.4.0-beta.2', '1.4.0'],
    ['dev', null],
    [null, null],
]);
