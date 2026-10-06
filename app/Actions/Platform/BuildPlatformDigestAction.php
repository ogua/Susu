<?php

namespace App\Actions\Platform;

use App\Enums\InvoiceStatus;
use App\Filament\SuperAdmin\Pages\Devices;
use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\NotificationLog;
use App\Models\SubscriptionInvoice;
use App\Models\SyncOp;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The morning health check for super admins (platform:digest): what broke
 * or needs chasing in the last 24 hours, so failures that only show on
 * their own pages (exports, SMS, sync, billing) are not missed.
 *
 * @phpstan-type DigestItem array{label: string, value: string, alert: bool}
 */
class BuildPlatformDigestAction
{
    /** Cache key RunBillingCycle writes its last summary to. */
    public const BILLING_RUN_CACHE_KEY = 'platform:billing:last_run';

    /**
     * @return array{items: list<DigestItem>, alerts: int}
     */
    public function execute(): array
    {
        $since = now()->subDay();
        $lastRun = Cache::get(self::BILLING_RUN_CACHE_KEY);
        $lastRunAt = isset($lastRun['at']) ? CarbonImmutable::parse($lastRun['at']) : null;
        $billingMissed = $lastRunAt === null || $lastRunAt->lt(now()->subHours(26));

        $overdue = SubscriptionInvoice::query()->where('status', InvoiceStatus::Unpaid)->where('due_at', '<', now());
        $tokens = PersonalAccessToken::query()->where('tokenable_type', (new User)->getMorphClass());

        $items = [
            $this->item('Billing run', $lastRunAt === null ? 'Has never run' : 'Last ran '.$lastRunAt->diffForHumans(), $billingMissed),
            $this->item('Overdue invoices', (clone $overdue)->count().' ('.Money::format((int) (clone $overdue)->sum('amount')).')', (clone $overdue)->exists()),
            $this->item('Invoices paid (24h)', (string) SubscriptionInvoice::query()->where('status', InvoiceStatus::Paid)->where('paid_at', '>=', $since)->count(), false),
            $this->item('Suspended for non-payment', (string) Company::query()->where('suspended_reason', Company::SUSPENDED_FOR_NON_PAYMENT)->count(), false),
            $this->item('Onboarding incomplete', (string) Company::query()->where('is_active', true)->onboardingIncomplete()->count(), Company::query()->where('is_active', true)->onboardingIncomplete()->exists()),
            $this->item('Rejected sync operations (24h)', (string) ($rejected = SyncOp::query()->where('status', 'rejected')->where('created_at', '>=', $since)->count()), $rejected > 0),
            $this->item('Failed SMS (24h)', (string) ($failedSms = NotificationLog::query()->where('status', 'failed')->where('created_at', '>=', $since)->count()), $failedSms > 0),
            $this->item('Failed data exports (24h)', (string) ($failedExports = CompanyExport::query()->where('status', CompanyExport::STATUS_FAILED)->where('updated_at', '>=', $since)->count()), $failedExports > 0),
            $this->item('Stale devices', (string) (clone $tokens)->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subDays(Devices::STALE_AFTER_DAYS)))->count(), false),
        ];

        return [
            'items' => $items,
            'alerts' => collect($items)->where('alert', true)->count(),
        ];
    }

    /**
     * @return DigestItem
     */
    private function item(string $label, string $value, bool $alert): array
    {
        return ['label' => $label, 'value' => $value, 'alert' => $alert];
    }
}
