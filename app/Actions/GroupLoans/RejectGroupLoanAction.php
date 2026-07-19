<?php

namespace App\Actions\GroupLoans;

use App\Enums\GroupLoanStatus;
use App\Models\GroupLoan;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RejectGroupLoanAction
{
    public function execute(GroupLoan $groupLoan, User $rejectedBy, string $reason): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Applied) {
            throw ValidationException::withMessages(['status' => 'Only applied group loans can be rejected.']);
        }

        $groupLoan->forceFill([
            'status' => GroupLoanStatus::Rejected,
            'approved_by' => $rejectedBy->id,
            'rejection_reason' => $reason,
        ])->save();

        return $groupLoan->fresh();
    }
}
