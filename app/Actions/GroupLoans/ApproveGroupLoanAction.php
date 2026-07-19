<?php

namespace App\Actions\GroupLoans;

use App\Enums\GroupLoanStatus;
use App\Models\GroupLoan;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApproveGroupLoanAction
{
    public function execute(GroupLoan $groupLoan, User $approvedBy): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Applied) {
            throw ValidationException::withMessages(['status' => 'Only applied group loans can be approved.']);
        }

        $groupLoan->forceFill([
            'status' => GroupLoanStatus::Approved,
            'approved_by' => $approvedBy->id,
            'approved_at' => now(),
        ])->save();

        return $groupLoan->fresh();
    }
}
