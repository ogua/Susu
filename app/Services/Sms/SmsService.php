<?php

namespace App\Services\Sms;

use App\Models\Company;
use App\Models\CompanySmsSetting;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Company-scoped SMS sending. Each company configures its own provider and
 * registered sender ID (company_sms_settings); the log driver is the default
 * so development needs no provider account. Arkesel is the production driver.
 */
class SmsService
{
    public function settingsFor(Company $company): CompanySmsSetting
    {
        // Explicit rather than relying on the migration's column defaults:
        // Eloquent's create() never reflects those back onto the in-memory
        // model firstOrCreate() returns, so the very first lookup for a
        // company (before any row exists) would otherwise see
        // notifications_enabled/provider as null instead of their real
        // defaults — silently dropping that company's first notification.
        return CompanySmsSetting::firstOrCreate(
            ['company_id' => $company->id],
            [
                'provider' => 'log',
                'notifications_enabled' => true,
                'quiet_hours_start' => '21:00',
                'quiet_hours_end' => '07:00',
            ],
        );
    }

    public function send(Company $company, string $to, string $body): ?string
    {
        $settings = $this->settingsFor($company);

        return $this->driver($settings->provider, $settings->api_key)->send($to, $body, $settings->sender_id);
    }

    /**
     * For notifications with no Company context (e.g. desktop license-key
     * delivery to a guest purchaser) — uses the platform-level driver config
     * (services.platform_sms) instead of a company's CompanySmsSetting.
     */
    public function sendSystem(string $to, string $body): ?string
    {
        $senderId = config('services.platform_sms.sender_id');

        return $this->driver(
            config('services.platform_sms.provider', 'log'),
            config('services.platform_sms.api_key'),
        )->send($to, $body, $senderId);
    }

    /**
     * Seconds to delay a notification so it lands outside the company's
     * quiet-hours window (G9: a 2am sync replay must not text customers at 2am).
     */
    public function quietHoursDelay(Company $company, CarbonInterface $now): int
    {
        $settings = $this->settingsFor($company);

        $start = $now->copy()->setTimeFromTimeString($settings->quiet_hours_start);
        $end = $now->copy()->setTimeFromTimeString($settings->quiet_hours_end);

        if ($start->lessThanOrEqualTo($end)) {
            // Window within one day (e.g. 01:00–06:00).
            $inWindow = $now->between($start, $end);
            $release = $end;
        } else {
            // Window crosses midnight (default 21:00–07:00).
            $inWindow = $now->greaterThanOrEqualTo($start) || $now->lessThan($end);
            $release = $now->greaterThanOrEqualTo($start) ? $end->addDay() : $end;
        }

        return $inWindow ? max(0, $now->diffInSeconds($release, false)) : 0;
    }

    protected function driver(string $provider, ?string $apiKey = null): SmsDriver
    {
        return match ($provider) {
            'log' => new LogSmsDriver,
            'arkesel' => new ArkeselSmsDriver((string) $apiKey, (string) config('services.arkesel.base_url', 'https://sms.arkesel.com')),
            default => throw new InvalidArgumentException(
                "SMS provider '{$provider}' is not implemented yet."
            ),
        };
    }
}
