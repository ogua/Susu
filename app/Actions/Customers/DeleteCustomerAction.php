<?php

namespace App\Actions\Customers;

use App\Enums\AccountStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanStatus;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes a customer, but only once nothing financial is still open
 * for them — otherwise their accounts and loans would vanish from every
 * customer-scoped screen while still holding money.
 */
class DeleteCustomerAction
{
    public function execute(Customer $customer): void
    {
        if ($reason = $this->blockingReason($customer)) {
            throw ValidationException::withMessages(['customer' => $reason]);
        }

        $customer->delete();
    }

    public function blockingReason(Customer $customer): ?string
    {
        return match (true) {
            $customer->savingsAccounts()->where('status', '!=', AccountStatus::Closed)->exists() => 'Close this customer\'s savings accounts first.',
            $customer->loans()->whereIn('status', [LoanStatus::Applied, LoanStatus::Approved, LoanStatus::Disbursed])->exists() => 'This customer has an open loan.',
            $customer->groupLoans()->whereIn('status', [GroupLoanStatus::Draft, GroupLoanStatus::Active])->exists() => 'This customer has an open group loan.',
            $customer->loanGroupMemberships()->where('status', 'active')->exists(),
            $customer->groupMemberships()->where('status', 'active')->exists() => 'Remove this customer from their groups first.',
            default => null,
        };
    }
}
