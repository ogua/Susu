<?php

namespace App\Policies;

use App\Models\LoanGroup;
use App\Models\User;

class LoanGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function view(User $user, LoanGroup $loanGroup): bool
    {
        return $user->company_id === $loanGroup->company_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function update(User $user, LoanGroup $loanGroup): bool
    {
        return $user->company_id === $loanGroup->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
