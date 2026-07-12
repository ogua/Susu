<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WithdrawalRequest;

class WithdrawalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, WithdrawalRequest $request): bool
    {
        return $user->company_id === $request->company_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent', 'customer']);
    }

    public function approve(User $user, WithdrawalRequest $request): bool
    {
        return $user->company_id === $request->company_id
            && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function reject(User $user, WithdrawalRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    public function pay(User $user, WithdrawalRequest $request): bool
    {
        return $this->approve($user, $request);
    }
}
