<?php

namespace App\Http\Resources\V1;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company's plan, billing status, usage against limits and open invoices.
 * Amounts are minor units (pesewas), as everywhere in the API.
 *
 * @mixin Company
 */
class CompanySubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->subscription;
        $plan = $subscription?->plan;

        return [
            'plan' => $plan === null ? null : [
                'id' => $plan->id,
                'name' => $plan->name,
                'code' => $plan->code,
                'price_amount' => $plan->price_amount,
                'currency' => $plan->currency,
                'billing_period' => $plan->billing_period->value,
            ],
            'status' => $subscription?->status->value,
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
            'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
            'usage' => app(PlanLimits::class)->summary($this->resource),
            'open_invoices' => $this->subscriptionInvoices
                ->where('status', InvoiceStatus::Unpaid)
                ->sortBy('due_at')
                ->values()
                ->map(fn (SubscriptionInvoice $invoice): array => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'amount' => $invoice->amount,
                    'currency' => $invoice->currency,
                    'period_start' => $invoice->period_start->toIso8601String(),
                    'period_end' => $invoice->period_end->toIso8601String(),
                    'due_at' => $invoice->due_at->toIso8601String(),
                    'is_overdue' => $invoice->isOverdue(),
                ]),
        ];
    }
}
