<?php

namespace App\Actions\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApproveLoanAction
{
    public function execute(Loan $loan, User $approvedBy): Loan
    {
        if ($loan->status !== LoanStatus::Applied) {
            throw ValidationException::withMessages(['status' => 'Only applied loans can be approved.']);
        }

        $loan->forceFill([
            'status' => LoanStatus::Approved,
            'approved_by' => $approvedBy->id,
            'approved_at' => now(),
        ])->save();

        return $loan->fresh();
    }
}
