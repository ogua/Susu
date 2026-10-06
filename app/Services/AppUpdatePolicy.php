<?php

namespace App\Services;

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use Illuminate\Support\Facades\Cache;

/**
 * Decides what a client build should be told about updates: the newest
 * published release for its platform and the version floor below which it
 * must update before it can be used.
 *
 * The floor is the highest of every active release's minimum supported
 * version and every active *forced* release's own version — so a forced
 * security release keeps blocking older devices even after a later,
 * unforced release supersedes it, and a device already at or above it is
 * never blocked by a flag left on.
 */
class AppUpdatePolicy
{
    private const CACHE_SECONDS = 300;

    private const NO_VERSION = '0.0.0';

    /**
     * @return array{latest_version: string, minimum_version: string, update_available: bool, force_update: bool, update_message: string|null, store_url_android: string|null, store_url_ios: string|null, download_url: string|null, platform: string|null}
     */
    public function resolve(?string $platform, ?string $callerVersion): array
    {
        $os = AppPlatform::tryFrom((string) $platform);
        $caller = self::normalize($callerVersion);
        $releases = $os ? $this->activeReleases($os) : [];

        $storeUrls = [
            AppPlatform::Android->value => config('app_updates.store_url_android'),
            AppPlatform::Ios->value => config('app_updates.store_url_ios'),
            AppPlatform::Desktop->value => config('app_updates.download_url_desktop'),
        ];

        if ($releases === []) {
            return [
                'latest_version' => self::NO_VERSION,
                'minimum_version' => self::NO_VERSION,
                'update_available' => false,
                'force_update' => false,
                'update_message' => null,
                'store_url_android' => $storeUrls[AppPlatform::Android->value],
                'store_url_ios' => $storeUrls[AppPlatform::Ios->value],
                'download_url' => $os ? $storeUrls[$os->value] : null,
                'platform' => $os?->value,
            ];
        }

        $latest = $releases[0];
        $floor = self::NO_VERSION;

        foreach ($releases as $release) {
            foreach ([$release['minimum_supported_version'], $release['is_forced_update'] ? $release['version'] : null] as $candidate) {
                if ($candidate !== null && version_compare(self::normalize($candidate) ?? self::NO_VERSION, $floor, '>')) {
                    $floor = self::normalize($candidate);
                }
            }
        }

        // A floor above the newest release would block everyone with nothing to update to.
        if (version_compare($floor, $latest['version'], '>')) {
            $floor = $latest['version'];
        }

        $storeUrls[$os->value] = $latest['store_url'] ?: $storeUrls[$os->value];

        return [
            'latest_version' => $latest['version'],
            'minimum_version' => $floor,
            'update_available' => $caller !== null && version_compare($latest['version'], $caller, '>'),
            'force_update' => $caller !== null && version_compare($caller, $floor, '<'),
            'update_message' => $latest['release_notes'],
            'store_url_android' => $storeUrls[AppPlatform::Android->value],
            'store_url_ios' => $storeUrls[AppPlatform::Ios->value],
            'download_url' => $storeUrls[$os->value],
            'platform' => $os->value,
        ];
    }

    public static function forget(AppPlatform $platform): void
    {
        Cache::forget(self::cacheKey($platform));
    }

    /**
     * Plain major.minor.patch: missing segments become 0 and pre-release or
     * build suffixes ("1.2.0-beta", "1.0-SNAPSHOT") are ignored. Null when
     * the string doesn't start with a number at all (e.g. a "dev" build).
     */
    public static function normalize(?string $version): ?string
    {
        if ($version === null || ! preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/', trim($version), $matches)) {
            return null;
        }

        return sprintf('%d.%d.%d', $matches[1], $matches[2] ?? 0, $matches[3] ?? 0);
    }

    /**
     * Active releases for a platform, newest first by version_code.
     *
     * @return list<array{version: string, minimum_supported_version: string|null, is_forced_update: bool, release_notes: string|null, store_url: string|null}>
     */
    private function activeReleases(AppPlatform $platform): array
    {
        return Cache::remember(self::cacheKey($platform), self::CACHE_SECONDS, fn (): array => AppVersion::query()
            ->active()
            ->where('platform', $platform)
            ->orderByDesc('version_code')
            ->get()
            ->map(fn (AppVersion $release): array => [
                'version' => self::normalize($release->version) ?? self::NO_VERSION,
                'minimum_supported_version' => $release->minimum_supported_version,
                'is_forced_update' => $release->is_forced_update,
                'release_notes' => $release->release_notes,
                'store_url' => $release->store_url,
            ])
            ->all());
    }

    private static function cacheKey(AppPlatform $platform): string
    {
        return "app-update-policy:{$platform->value}";
    }
}
