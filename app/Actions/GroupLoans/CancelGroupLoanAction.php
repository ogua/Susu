<?php

namespace App\Actions\GroupLoans;

use App\Enums\GroupLoanStatus;
use App\Models\GroupLoan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a group loan that was issued but never activated (e.g. issued by
 * mistake, or the member pulled out). Nothing was disbursed, and any security
 * deposit already paid lives in the member's own savings account as ordinary
 * savings, so there is nothing to reverse — the loan simply stops being the
 * member's open loan, freeing them to be issued a new one or removed.
 * Re-cancelling an already-cancelled loan is a no-op so offline replays are safe.
 */
class CancelGroupLoanAction
{
    public function execute(GroupLoan $groupLoan, User $cancelledBy, ?string $reason = null): GroupLoan
    {
        if ($groupLoan->status === GroupLoanStatus::Cancelled) {
            return $groupLoan;
        }
        if ($groupLoan->status !== GroupLoanStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only a loan that has not been activated can be cancelled. Use write-off for an active loan.',
            ]);
        }

        return DB::transaction(function () use ($groupLoan, $cancelledBy, $reason): GroupLoan {
            /** @var GroupLoan $locked */
            $locked = GroupLoan::whereKey($groupLoan->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GroupLoanStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'This loan is no longer a draft.']);
            }

            $locked->forceFill([
                'status' => GroupLoanStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy->id,
                'cancellation_reason' => filled($reason) ? $reason : null,
            ])->save();

            return $locked->fresh();
        });
    }
}
