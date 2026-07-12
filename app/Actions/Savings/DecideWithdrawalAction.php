<?php

namespace App\Actions\Savings;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Validation\ValidationException;

/** Approve or reject a pending withdrawal (branch_manager and above). */
class DecideWithdrawalAction
{
    public function approve(User $approvedBy, WithdrawalRequest $request): WithdrawalRequest
    {
        $this->assertPending($request);

        $request->update([
            'status' => WithdrawalStatus::Approved,
            'approved_by' => $approvedBy->id,
        ]);

        return $request;
    }

    public function reject(User $rejectedBy, WithdrawalRequest $request, string $reason): WithdrawalRequest
    {
        $this->assertPending($request);

        $request->update([
            'status' => WithdrawalStatus::Rejected,
            'approved_by' => $rejectedBy->id,
            'rejected_reason' => $reason,
        ]);

        return $request;
    }

    private function assertPending(WithdrawalRequest $request): void
    {
        if ($request->status !== WithdrawalStatus::Pending) {
            throw ValidationException::withMessages(['request' => 'Only pending requests can be decided.']);
        }
    }
}
