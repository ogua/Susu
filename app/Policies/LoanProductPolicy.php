<?php

namespace App\Policies;

use App\Models\LoanProduct;
use App\Models\User;

class LoanProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function view(User $user, LoanProduct $product): bool
    {
        return $user->company_id === $product->company_id && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('company_admin');
    }

    public function update(User $user, LoanProduct $product): bool
    {
        return $user->company_id === $product->company_id && $user->hasRole('company_admin');
    }

    public function delete(User $user, LoanProduct $product): bool
    {
        return $user->company_id === $product->company_id && $user->hasRole('company_admin');
    }
}
