<?php

namespace App\Actions\Company;

use App\Actions\Billing\SubscribeCompanyAction;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Offboards a company that has left the platform: it is suspended (everyone
 * signed out), its subscription cancelled and open invoices voided, and it
 * drops off the default Companies list. Nothing is deleted — records stay
 * for the retention period (platform.data_retention_days), after which
 * ErasePersonalDataAction may anonymise its people. Restoring leaves the
 * company suspended until an operator reactivates it.
 */
class ArchiveCompanyAction
{
    public function __construct(
        private readonly SetCompanyActiveStatusAction $setCompanyStatus,
        private readonly SubscribeCompanyAction $subscribeCompany,
    ) {}

    public function archive(Company $company): Company
    {
        if ($company->archived_at !== null) {
            return $company;
        }

        return DB::transaction(function () use ($company): Company {
            $this->setCompanyStatus->execute($company, false, Company::SUSPENDED_ARCHIVED);

            $subscription = $company->subscription;
            if ($subscription !== null && $subscription->status !== SubscriptionStatus::Cancelled) {
                $this->subscribeCompany->cancel($subscription);
            }

            $company->subscriptionInvoices()->where('status', InvoiceStatus::Unpaid)->update(['status' => InvoiceStatus::Void]);
            $company->update(['archived_at' => now()]);

            return $company;
        });
    }

    public function restore(Company $company): Company
    {
        if ($company->personal_data_erased_at !== null) {
            throw ValidationException::withMessages(['company' => 'This company\'s personal data has been erased; it cannot be restored.']);
        }

        $company->update(['archived_at' => null, 'suspended_reason' => Company::SUSPENDED_BY_OPERATOR]);

        return $company;
    }
}
