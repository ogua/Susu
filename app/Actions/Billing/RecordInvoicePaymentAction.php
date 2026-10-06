<?php

namespace App\Actions\Billing;

use App\Actions\Company\SetCompanyActiveStatusAction;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settles an invoice — by Paystack (CompleteInvoiceCheckoutAction) or by a
 * super admin recording a bank/MoMo/cash payment. Idempotent: an already
 * paid invoice is returned untouched. Once nothing is left overdue the
 * subscription is current again, and a suspension for non-payment is lifted
 * (an operator's own suspension is not).
 */
class RecordInvoicePaymentAction
{
    public function __construct(private readonly SetCompanyActiveStatusAction $setCompanyStatus) {}

    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function execute(
        SubscriptionInvoice $invoice,
        string $method,
        ?string $reference = null,
        ?User $recordedBy = null,
        ?array $rawResponse = null,
    ): SubscriptionInvoice {
        return DB::transaction(function () use ($invoice, $method, $reference, $recordedBy, $rawResponse): SubscriptionInvoice {
            /** @var SubscriptionInvoice $locked */
            $locked = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === InvoiceStatus::Paid) {
                return $locked;
            }

            if ($locked->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages(['invoice' => 'This invoice has been voided and cannot be paid.']);
            }

            $locked->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
                'payment_method' => $method,
                'payment_reference' => $reference,
                'recorded_by' => $recordedBy?->id,
                'raw_response' => $rawResponse ?? $locked->raw_response,
            ]);

            $subscription = $locked->subscription;
            $stillOverdue = $subscription->unpaidInvoices()->where('due_at', '<', now())->exists();

            if (! $stillOverdue && $subscription->status === SubscriptionStatus::PastDue) {
                $subscription->update(['status' => SubscriptionStatus::Active]);
            }

            $company = $locked->company;
            if (! $stillOverdue && ! $company->is_active && $company->suspended_reason === Company::SUSPENDED_FOR_NON_PAYMENT) {
                $this->setCompanyStatus->execute($company, true);
            }

            return $locked;
        });
    }

    public function void(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->status === InvoiceStatus::Paid) {
            throw ValidationException::withMessages(['invoice' => 'A paid invoice cannot be voided.']);
        }

        $invoice->update(['status' => InvoiceStatus::Void]);

        return $invoice;
    }
}
