<?php

namespace App\Actions\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Puts a company on a plan.
 *
 * - First subscription: starts a trial when the plan has one, otherwise
 *   opens the first period and invoices it.
 * - Plan change: switches the plan immediately for limits; the new price is
 *   billed from the next period (no proration), so open invoices stand.
 * - A cancelled subscription is restarted as if new (no second trial).
 */
class SubscribeCompanyAction
{
    public function __construct(private readonly IssueInvoiceAction $issueInvoice) {}

    public function execute(Company $company, Plan $plan): CompanySubscription
    {
        return DB::transaction(function () use ($company, $plan): CompanySubscription {
            $subscription = $company->subscription()->lockForUpdate()->first();

            if ($subscription !== null && $subscription->status !== SubscriptionStatus::Cancelled) {
                $subscription->update(['plan_id' => $plan->id]);

                return $subscription;
            }

            $isFirst = $subscription === null;
            $subscription ??= new CompanySubscription(['company_id' => $company->id]);
            $subscription->fill(['plan_id' => $plan->id, 'cancelled_at' => null]);

            if ($isFirst && $plan->trial_days > 0) {
                $subscription->fill([
                    'status' => SubscriptionStatus::Trialing,
                    'trial_ends_at' => now()->addDays($plan->trial_days),
                    'current_period_start' => null,
                    'current_period_end' => null,
                ])->save();

                return $subscription;
            }

            $start = now();
            $subscription->fill([
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'current_period_start' => $start,
                'current_period_end' => $plan->billing_period->endFrom($start),
            ])->save();

            $this->issueInvoice->execute($subscription->setRelation('plan', $plan), $start);

            return $subscription;
        });
    }

    public function cancel(CompanySubscription $subscription): CompanySubscription
    {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $subscription;
    }
}
