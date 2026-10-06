<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\CompanySubscription;
use App\Models\SubscriptionInvoice;
use App\Notifications\SubscriptionInvoiceNotice;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Bills one period of a subscription at its plan's current price. One
 * invoice per (subscription, period start) — re-running for the same period
 * returns the existing invoice. A free plan's invoice is settled at once;
 * any other is emailed to the company (NotifyInvoiceAction).
 */
class IssueInvoiceAction
{
    public function __construct(private readonly NotifyInvoiceAction $notifyInvoice) {}

    /**
     * @param  ?int  $amount  Override the plan price (pro-rata upgrade invoices).
     * @param  ?CarbonInterface  $periodEnd  Override the period end (pro-rata invoices end with the current period).
     */
    public function execute(CompanySubscription $subscription, CarbonInterface $periodStart, ?int $amount = null, ?CarbonInterface $periodEnd = null): SubscriptionInvoice
    {
        $existing = $subscription->invoices()->where('period_start', $periodStart)->first();
        if ($existing !== null) {
            return $existing;
        }

        $plan = $subscription->plan;
        $amount ??= $plan->price_amount;
        $isFree = $amount === 0;

        $invoice = $subscription->invoices()->create([
            'company_id' => $subscription->company_id,
            'plan_id' => $plan->id,
            'number' => 'INV-'.$periodStart->format('Ymd').'-'.Str::upper(Str::random(6)),
            'amount' => $amount,
            'currency' => $plan->currency,
            'period_start' => $periodStart,
            'period_end' => $periodEnd ?? $plan->billing_period->endFrom($periodStart),
            'due_at' => $periodStart->copy()->addDays((int) config('billing.grace_days')),
            'status' => $isFree ? InvoiceStatus::Paid : InvoiceStatus::Unpaid,
            'paid_at' => $isFree ? now() : null,
            'payment_method' => $isFree ? 'free' : null,
        ]);

        if (! $isFree) {
            $this->notifyInvoice->execute($invoice, SubscriptionInvoiceNotice::ISSUED);
        }

        return $invoice;
    }
}
