<?php

namespace App\Actions\LoanGroups;

use App\Enums\GroupLoanStatus;
use App\Models\LoanGroupMember;
use Illuminate\Validation\ValidationException;

/**
 * A LoanGroup roster has no activation freeze — members can be removed
 * anytime, except while they still have an open group loan: an active one, or a
 * draft awaiting activation (cancel the draft first).
 */
class RemoveLoanGroupMemberAction
{
    public function execute(LoanGroupMember $member): LoanGroupMember
    {
        $openLoan = $member->groupLoans()
            ->whereIn('status', [GroupLoanStatus::Draft, GroupLoanStatus::Active])
            ->first();

        if ($openLoan?->status === GroupLoanStatus::Active) {
            throw ValidationException::withMessages([
                'member' => 'This member cannot be removed while they have an active loan in the group.',
            ]);
        }
        if ($openLoan?->status === GroupLoanStatus::Draft) {
            throw ValidationException::withMessages([
                'member' => "This member has loan {$openLoan->loan_number} awaiting activation. Cancel it before removing the member.",
            ]);
        }

        $member->forceFill([
            'status' => 'left',
            'left_at' => now(),
        ])->save();

        return $member->fresh();
    }
}
