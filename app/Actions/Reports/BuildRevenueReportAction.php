<?php

namespace App\Actions\Reports;

use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Enums\LicenseSaleStatus;
use App\Enums\SubscriptionStatus;
use App\Models\CompanySubscription;
use App\Models\DesktopLicenseSale;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;

/**
 * The platform's own income: subscription invoices paid and desktop
 * licenses sold, month by month, plus recurring revenue and receivables.
 * Subscription revenue is counted when paid (cash basis), on paid_at.
 * MRR is the monthly-equivalent price of every active or past-due paid
 * subscription (trials excluded, yearly plans divided by 12).
 *
 * @phpstan-type RevenueMonth array{month: string, label: string, subscriptions: int, licenses: int, total: int}
 */
class BuildRevenueReportAction
{
    /**
     * @return array{months: list<RevenueMonth>, mrr: int, outstanding: int, overdue: int, paying_companies: int, trialing_companies: int}
     */
    public function execute(int $monthCount = 12): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($monthCount - 1);

        $subscriptions = SubscriptionInvoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->where('paid_at', '>=', $start)
            ->get(['paid_at', 'amount'])
            ->groupBy(fn (SubscriptionInvoice $invoice): string => $invoice->paid_at->format('Y-m'))
            ->map(fn ($group): int => (int) $group->sum('amount'));

        $licenses = DesktopLicenseSale::query()
            ->whereIn('status', [LicenseSaleStatus::Paid, LicenseSaleStatus::Issued])
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'amount'])
            ->groupBy(fn (DesktopLicenseSale $sale): string => $sale->created_at->format('Y-m'))
            ->map(fn ($group): int => (int) $group->sum('amount'));

        $months = [];
        foreach (range(0, $monthCount - 1) as $offset) {
            $month = $start->addMonths($offset);
            $key = $month->format('Y-m');
            $subscriptionRevenue = (int) ($subscriptions[$key] ?? 0);
            $licenseRevenue = (int) ($licenses[$key] ?? 0);

            $months[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'subscriptions' => $subscriptionRevenue,
                'licenses' => $licenseRevenue,
                'total' => $subscriptionRevenue + $licenseRevenue,
            ];
        }

        $paying = CompanySubscription::query()
            ->with('plan')
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->get();

        $unpaid = SubscriptionInvoice::query()->where('status', InvoiceStatus::Unpaid);

        return [
            'months' => $months,
            'mrr' => (int) round($paying->sum(fn (CompanySubscription $subscription): float => $subscription->plan->billing_period === BillingPeriod::Yearly
                ? $subscription->plan->price_amount / 12
                : $subscription->plan->price_amount)),
            'outstanding' => (int) (clone $unpaid)->sum('amount'),
            'overdue' => (int) (clone $unpaid)->where('due_at', '<', now())->sum('amount'),
            'paying_companies' => $paying->filter(fn (CompanySubscription $subscription): bool => $subscription->plan->price_amount > 0)->count(),
            'trialing_companies' => CompanySubscription::where('status', SubscriptionStatus::Trialing)->count(),
        ];
    }
}
