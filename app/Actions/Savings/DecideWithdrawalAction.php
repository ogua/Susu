<?php

namespace App\Actions\Savings;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve or reject a pending withdrawal (branch_manager and above).
 *
 * Maker-checker: whoever raised the request can't approve it, unless they
 * are a company admin (small branches may have no second manager).
 */
class DecideWithdrawalAction
{
    public function approve(User $approvedBy, WithdrawalRequest $request): WithdrawalRequest
    {
        return DB::transaction(function () use ($approvedBy, $request): WithdrawalRequest {
            $locked = $this->lockPending($request);

            if ($locked->requested_by === $approvedBy->id && ! $approvedBy->hasAnyRole(['company_admin', 'super_admin'])) {
                throw ValidationException::withMessages(['request' => 'You cannot approve a withdrawal you requested.']);
            }

            $locked->update([
                'status' => WithdrawalStatus::Approved,
                'approved_by' => $approvedBy->id,
            ]);

            return $request->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function reject(User $rejectedBy, WithdrawalRequest $request, string $reason): WithdrawalRequest
    {
        return DB::transaction(function () use ($rejectedBy, $request, $reason): WithdrawalRequest {
            $locked = $this->lockPending($request);

            $locked->update([
                'status' => WithdrawalStatus::Rejected,
                'approved_by' => $rejectedBy->id,
                'rejected_reason' => $reason,
            ]);

            return $request->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function lockPending(WithdrawalRequest $request): WithdrawalRequest
    {
        $locked = WithdrawalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== WithdrawalStatus::Pending) {
            throw ValidationException::withMessages(['request' => 'Only pending requests can be decided.']);
        }

        return $locked;
    }
}
