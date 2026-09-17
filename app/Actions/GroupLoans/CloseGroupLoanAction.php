<?php

namespace App\Actions\GroupLoans;

use App\Enums\GroupLoanStatus;
use App\Models\GroupLoan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a fully-repaid group loan. Any security deposit paid in earlier
 * already lives in the member's ordinary savings account, so closing simply
 * flips status/closed_at — there is nothing left to refund.
 */
class CloseGroupLoanAction
{
    public function execute(GroupLoan $groupLoan, User $closedBy): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Active) {
            throw ValidationException::withMessages(['status' => 'Only an active group loan can be closed.']);
        }
        if ($groupLoan->outstanding_balance > 0) {
            throw ValidationException::withMessages(['status' => 'This group loan still has an outstanding balance.']);
        }

        return DB::transaction(function () use ($groupLoan): GroupLoan {
            $groupLoan->forceFill([
                'status' => GroupLoanStatus::Closed,
                'closed_at' => now(),
            ])->save();

            return $groupLoan->fresh();
        });
    }
}
