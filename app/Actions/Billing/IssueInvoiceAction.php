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

    public function execute(CompanySubscription $subscription, CarbonInterface $periodStart): SubscriptionInvoice
    {
        $existing = $subscription->invoices()->where('period_start', $periodStart)->first();
        if ($existing !== null) {
            return $existing;
        }

        $plan = $subscription->plan;
        $isFree = $plan->price_amount === 0;

        $invoice = $subscription->invoices()->create([
            'company_id' => $subscription->company_id,
            'plan_id' => $plan->id,
            'number' => 'INV-'.$periodStart->format('Ymd').'-'.Str::upper(Str::random(6)),
            'amount' => $plan->price_amount,
            'currency' => $plan->currency,
            'period_start' => $periodStart,
            'period_end' => $plan->billing_period->endFrom($periodStart),
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
