<?php

namespace App\Actions\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Plan;
use App\Services\Billing\PlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a company on a plan — used by super admins and by company admins
 * themselves (web Billing page, POST /api/v1/company/subscription/plan).
 *
 * - First subscription: starts a trial when the plan has one, otherwise
 *   opens the first period and invoices it.
 * - Plan change: refused when the company already uses more than the new
 *   plan allows. Limits switch immediately. On a trial nothing is billed.
 *   Same billing period: an upgrade is invoiced pro rata for the rest of
 *   the current period; a downgrade takes its lower price from the next
 *   period (no refund). Different billing period (monthly ⇄ yearly): a new
 *   period starts now on the new plan and is invoiced in full.
 * - A cancelled subscription is restarted as if new (no second trial).
 */
class SubscribeCompanyAction
{
    public function __construct(
        private readonly IssueInvoiceAction $issueInvoice,
        private readonly PlanLimits $planLimits,
    ) {}

    public function execute(Company $company, Plan $plan): CompanySubscription
    {
        return DB::transaction(function () use ($company, $plan): CompanySubscription {
            $subscription = $company->subscription()->with('plan')->lockForUpdate()->first();

            if ($subscription !== null && $subscription->status !== SubscriptionStatus::Cancelled) {
                return $this->changePlan($company, $subscription, $plan);
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

            $this->startPeriod($subscription, $plan);

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

    private function changePlan(Company $company, CompanySubscription $subscription, Plan $plan): CompanySubscription
    {
        $current = $subscription->plan;

        if ($current->is($plan)) {
            return $subscription;
        }

        $this->assertFits($company, $plan);

        if ($subscription->status === SubscriptionStatus::Trialing || $subscription->current_period_end === null) {
            $subscription->update(['plan_id' => $plan->id]);

            return $subscription;
        }

        if ($current->billing_period !== $plan->billing_period) {
            $subscription->fill(['plan_id' => $plan->id]);
            $this->startPeriod($subscription, $plan);

            return $subscription;
        }

        $subscription->update(['plan_id' => $plan->id]);

        if ($plan->price_amount > $current->price_amount) {
            $periodSeconds = max(1, $subscription->current_period_start->diffInSeconds($subscription->current_period_end));
            $remainingSeconds = max(0, now()->diffInSeconds($subscription->current_period_end, false));
            $prorated = (int) round(($plan->price_amount - $current->price_amount) * $remainingSeconds / $periodSeconds);

            if ($prorated > 0) {
                $this->issueInvoice->execute(
                    $subscription->setRelation('plan', $plan),
                    now(),
                    amount: $prorated,
                    periodEnd: $subscription->current_period_end,
                );
            }
        }

        return $subscription;
    }

    private function startPeriod(CompanySubscription $subscription, Plan $plan): void
    {
        $start = now();
        $subscription->fill([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_start' => $start,
            'current_period_end' => $plan->billing_period->endFrom($start),
        ])->save();

        $this->issueInvoice->execute($subscription->setRelation('plan', $plan), $start);
    }

    /** A plan change may not leave the company over its new limits. */
    private function assertFits(Company $company, Plan $plan): void
    {
        $over = collect(Plan::LIMITS)
            ->keys()
            ->filter(fn (string $resource): bool => $plan->limitFor($resource) !== null
                && $this->planLimits->usage($company, $resource) > $plan->limitFor($resource))
            ->map(fn (string $resource): string => $this->planLimits->usage($company, $resource)." {$resource} (plan allows {$plan->limitFor($resource)})")
            ->values();

        if ($over->isNotEmpty()) {
            throw ValidationException::withMessages([
                'plan_id' => "The {$plan->name} plan is too small: the company has ".$over->join(', ', ' and ').'.',
            ]);
        }
    }
}
