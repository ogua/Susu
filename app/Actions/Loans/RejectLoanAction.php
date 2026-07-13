<?php

namespace App\Actions\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RejectLoanAction
{
    public function execute(Loan $loan, User $rejectedBy, string $reason): Loan
    {
        if ($loan->status !== LoanStatus::Applied) {
            throw ValidationException::withMessages(['status' => 'Only applied loans can be rejected.']);
        }

        $loan->forceFill([
            'status' => LoanStatus::Rejected,
            'approved_by' => $rejectedBy->id,
            'rejection_reason' => $reason,
        ])->save();

        return $loan->fresh();
    }
}
