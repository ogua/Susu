<?php

namespace App\Policies;

use App\Models\GroupLoan;
use App\Models\User;

class GroupLoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function view(User $user, GroupLoan $groupLoan): bool
    {
        return $user->company_id === $groupLoan->company_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function recordDeposit(User $user, GroupLoan $groupLoan): bool
    {
        return $user->company_id === $groupLoan->company_id
            && $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function applyDeposit(User $user, GroupLoan $groupLoan): bool
    {
        return $user->company_id === $groupLoan->company_id
            && $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function activate(User $user, GroupLoan $groupLoan): bool
    {
        return $user->company_id === $groupLoan->company_id
            && $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function recordRepayment(User $user, GroupLoan $groupLoan): bool
    {
        return $user->company_id === $groupLoan->company_id
            && $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function writeOff(User $user, GroupLoan $groupLoan): bool
    {
        return $user->company_id === $groupLoan->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
