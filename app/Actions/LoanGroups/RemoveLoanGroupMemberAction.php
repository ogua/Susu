<?php

namespace App\Actions\LoanGroups;

use App\Enums\GroupLoanStatus;
use App\Models\LoanGroupMember;
use Illuminate\Validation\ValidationException;

/**
 * Unlike susu GroupMember (rotation freezes membership once the group
 * activates, so there's no remove action), a LoanGroup roster has no such
 * freeze — members can be removed anytime, except while they're a joint
 * debtor on a currently-disbursed, unclosed group loan.
 */
class RemoveLoanGroupMemberAction
{
    public function execute(LoanGroupMember $member): LoanGroupMember
    {
        $hasActiveDebt = $member->borrowerShares()
            ->whereHas('groupLoan', fn ($query) => $query->where('status', GroupLoanStatus::Disbursed))
            ->exists();

        if ($hasActiveDebt) {
            throw ValidationException::withMessages([
                'member' => 'This member cannot be removed while jointly liable on a disbursed, unclosed group loan.',
            ]);
        }

        $member->forceFill([
            'status' => 'left',
            'left_at' => now(),
        ])->save();

        return $member->fresh();
    }
}
