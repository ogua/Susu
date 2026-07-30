<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function view(User $user, Loan $loan): bool
    {
        return $user->company_id === $loan->company_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function approve(User $user, Loan $loan): bool
    {
        return $user->company_id === $loan->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function reject(User $user, Loan $loan): bool
    {
        return $user->company_id === $loan->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function disburse(User $user, Loan $loan): bool
    {
        return $user->company_id === $loan->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function recordRepayment(User $user, Loan $loan): bool
    {
        return $user->company_id === $loan->company_id
            && $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function writeOff(User $user, Loan $loan): bool
    {
        return $user->company_id === $loan->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function restructure(User $user, Loan $loan): bool
    {
        return $user->company_id === $loan->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function topUp(User $user, Loan $loan): bool
    {
        return $user->company_id === $loan->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
