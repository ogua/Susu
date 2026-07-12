<?php

namespace App\Services\Sms;

use App\Models\Company;
use App\Models\CompanySmsSetting;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Company-scoped SMS sending. Each company configures its own provider and
 * registered sender ID (company_sms_settings); the log driver is the default
 * so development needs no provider account. Arkesel/Hubtel drivers are added
 * when the production provider is chosen.
 */
class SmsService
{
    public function settingsFor(Company $company): CompanySmsSetting
    {
        return CompanySmsSetting::firstOrCreate(
            ['company_id' => $company->id],
            ['quiet_hours_start' => '21:00', 'quiet_hours_end' => '07:00'],
        );
    }

    public function send(Company $company, string $to, string $body): ?string
    {
        $settings = $this->settingsFor($company);

        return $this->driver($settings)->send($to, $body, $settings->sender_id);
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

    protected function driver(CompanySmsSetting $settings): SmsDriver
    {
        return match ($settings->provider) {
            'log' => new LogSmsDriver,
            default => throw new InvalidArgumentException(
                "SMS provider '{$settings->provider}' is not implemented yet."
            ),
        };
    }
}
