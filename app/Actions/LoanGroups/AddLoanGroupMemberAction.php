<?php

namespace App\Actions\LoanGroups;

use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use Illuminate\Validation\ValidationException;

class AddLoanGroupMemberAction
{
    public function execute(LoanGroup $loanGroup, Customer $customer): LoanGroupMember
    {
        if ($customer->company_id !== $loanGroup->company_id) {
            throw ValidationException::withMessages(['customer' => 'Customer not found in this company.']);
        }
        if ($loanGroup->members()->where('customer_id', $customer->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['customer' => 'This customer is already a member of the loan group.']);
        }

        return LoanGroupMember::create([
            'loan_group_id' => $loanGroup->id,
            'customer_id' => $customer->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
