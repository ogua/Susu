<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Operator-editable settings stored in platform_settings. Each one shadows a
 * config key (see SETTINGS): apply() writes stored values over config at
 * boot, so existing config() reads (license price, billing grace days, …)
 * honour the super admin's edits without knowing this class exists. A value
 * left blank falls back to the config/.env default.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings';

    /** @var array<string, array{config: string, type: 'int'|'string'}> setting key => config key */
    public const SETTINGS = [
        'license_price' => ['config' => 'license.price', 'type' => 'int'],
        'license_duration_days' => ['config' => 'license.duration_days', 'type' => 'int'],
        'billing_grace_days' => ['config' => 'billing.grace_days', 'type' => 'int'],
        'billing_suspend_after_days' => ['config' => 'billing.suspend_after_days', 'type' => 'int'],
        'support_email' => ['config' => 'platform.support_email', 'type' => 'string'],
        'support_phone' => ['config' => 'platform.support_phone', 'type' => 'string'],
        'data_retention_days' => ['config' => 'platform.data_retention_days', 'type' => 'int'],
    ];

    /**
     * @return array<string, ?string>
     */
    public function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => PlatformSetting::query()->pluck('value', 'key')->all());
    }

    /** Copies stored values over their config keys (called at boot). */
    public function apply(): void
    {
        try {
            $stored = $this->stored();
        } catch (Throwable) {
            // No database yet (fresh install, config:cache in CI): keep the defaults.
            return;
        }

        foreach (self::SETTINGS as $key => $setting) {
            $value = $stored[$key] ?? null;

            if ($value !== null && $value !== '') {
                config([$setting['config'] => $setting['type'] === 'int' ? (int) $value : $value]);
            }
        }
    }

    /**
     * Current effective values (stored, else config default), keyed by setting.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return collect(self::SETTINGS)
            ->map(fn (array $setting): mixed => config($setting['config']))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values  setting key => value (null/blank resets to the default)
     */
    public function save(array $values): void
    {
        foreach (array_intersect_key($values, self::SETTINGS) as $key => $value) {
            PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value === null || $value === '' ? null : (string) $value]);
        }

        Cache::forget(self::CACHE_KEY);
        $this->apply();
    }
}
