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
        if ($customer->branch_id !== $loanGroup->branch_id) {
            throw ValidationException::withMessages(['customer' => 'Only customers of the group\'s branch can join it — transfer the customer first.']);
        }
        if (! $loanGroup->is_active) {
            throw ValidationException::withMessages(['customer' => 'This group is deactivated.']);
        }
        if ($loanGroup->members()->where('customer_id', $customer->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['customer' => 'This customer is already a member of the loan group.']);
        }

        // (loan_group_id, customer_id) is unique, so a customer who left
        // rejoins on their old row rather than a new one.
        $member = $loanGroup->members()->where('customer_id', $customer->id)->first()
            ?? new LoanGroupMember(['loan_group_id' => $loanGroup->id, 'customer_id' => $customer->id]);

        $member->forceFill([
            'status' => 'active',
            'joined_at' => now(),
            'left_at' => null,
        ])->save();

        return $member;
    }
}
