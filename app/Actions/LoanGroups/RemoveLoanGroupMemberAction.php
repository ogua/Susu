<?php

namespace App\Actions\LoanGroups;

use App\Enums\GroupLoanStatus;
use App\Models\LoanGroupMember;
use Illuminate\Validation\ValidationException;

/**
 * A LoanGroup roster has no activation freeze — members can be removed
 * anytime, except while they still have an active (unclosed) group loan.
 */
class RemoveLoanGroupMemberAction
{
    public function execute(LoanGroupMember $member): LoanGroupMember
    {
        $hasActiveLoan = $member->groupLoans()
            ->where('status', GroupLoanStatus::Active)
            ->exists();

        if ($hasActiveLoan) {
            throw ValidationException::withMessages([
                'member' => 'This member cannot be removed while they have an active loan in the group.',
            ]);
        }

        $member->forceFill([
            'status' => 'left',
            'left_at' => now(),
        ])->save();

        return $member->fresh();
    }
}
