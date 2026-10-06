<?php

namespace App\Actions\Billing;

use App\Actions\Company\SetCompanyActiveStatusAction;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionInvoice;
use App\Notifications\SubscriptionInvoiceNotice;
use Illuminate\Support\Facades\DB;

/**
 * The daily billing run (billing:run):
 *
 * 1. Trials that have ended open their first period and are invoiced.
 * 2. Periods that have ended roll over and the new period is invoiced.
 * 3. Subscriptions with an overdue invoice become past due; companies get a
 *    reminder billing.reminder_days_before the due date and one overdue notice.
 * 4. Companies overdue for more than billing.suspend_after_days are
 *    suspended for non-payment (RecordInvoicePaymentAction lifts it).
 *
 * Safe to re-run: invoices are unique per period and each step only acts on
 * rows still in the state it looks for.
 */
class RunBillingCycleAction
{
    public function __construct(
        private readonly IssueInvoiceAction $issueInvoice,
        private readonly SetCompanyActiveStatusAction $setCompanyStatus,
        private readonly NotifyInvoiceAction $notifyInvoice,
    ) {}

    /**
     * @return array{trials_converted: int, renewed: int, past_due: int, suspended: int, reminders: int, overdue_notices: int}
     */
    public function execute(): array
    {
        $summary = ['trials_converted' => 0, 'renewed' => 0, 'past_due' => 0, 'suspended' => 0, 'reminders' => 0, 'overdue_notices' => 0];

        CompanySubscription::query()
            ->with('plan')
            ->where('status', SubscriptionStatus::Trialing)
            ->where('trial_ends_at', '<=', now())
            ->each(function (CompanySubscription $subscription) use (&$summary): void {
                DB::transaction(function () use ($subscription): void {
                    $start = $subscription->trial_ends_at;
                    $subscription->update([
                        'status' => SubscriptionStatus::Active,
                        'current_period_start' => $start,
                        'current_period_end' => $subscription->plan->billing_period->endFrom($start),
                    ]);
                    $this->issueInvoice->execute($subscription, $start);
                });
                $summary['trials_converted']++;
            });

        CompanySubscription::query()
            ->with('plan')
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->where('current_period_end', '<=', now())
            ->each(function (CompanySubscription $subscription) use (&$summary): void {
                // Catch up one period at a time, so a run missed for days still bills every period.
                while ($subscription->current_period_end->lessThanOrEqualTo(now())) {
                    DB::transaction(function () use ($subscription): void {
                        $start = $subscription->current_period_end;
                        $subscription->update([
                            'current_period_start' => $start,
                            'current_period_end' => $subscription->plan->billing_period->endFrom($start),
                        ]);
                        $this->issueInvoice->execute($subscription, $start);
                    });
                    $summary['renewed']++;
                }
            });

        $summary['past_due'] = CompanySubscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereHas('invoices', fn ($invoices) => $invoices
                ->where('status', InvoiceStatus::Unpaid)
                ->where('due_at', '<', now()))
            ->update(['status' => SubscriptionStatus::PastDue]);

        SubscriptionInvoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->whereNull('reminder_sent_at')
            ->whereBetween('due_at', [now(), now()->addDays((int) config('billing.reminder_days_before'))])
            ->each(function (SubscriptionInvoice $invoice) use (&$summary): void {
                $this->notifyInvoice->execute($invoice, SubscriptionInvoiceNotice::REMINDER);
                $invoice->update(['reminder_sent_at' => now()]);
                $summary['reminders']++;
            });

        SubscriptionInvoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->whereNull('overdue_notice_sent_at')
            ->where('due_at', '<', now())
            ->each(function (SubscriptionInvoice $invoice) use (&$summary): void {
                $this->notifyInvoice->execute($invoice, SubscriptionInvoiceNotice::OVERDUE);
                $invoice->update(['overdue_notice_sent_at' => now()]);
                $summary['overdue_notices']++;
            });

        $suspendBefore = now()->subDays((int) config('billing.suspend_after_days'));

        Company::query()
            ->where('is_active', true)
            ->whereHas('subscriptionInvoices', fn ($invoices) => $invoices
                ->where('status', InvoiceStatus::Unpaid)
                ->where('due_at', '<', $suspendBefore))
            ->each(function (Company $company) use (&$summary): void {
                $this->setCompanyStatus->execute($company, false, Company::SUSPENDED_FOR_NON_PAYMENT);
                $summary['suspended']++;
            });

        return $summary;
    }
}
