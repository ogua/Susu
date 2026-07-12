<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super_admin', 'company_admin']);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->hasRole('super_admin')
            || ($user->hasRole('company_admin') && $user->company_id === $company->id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasRole('super_admin')
            || ($user->hasRole('company_admin') && $user->company_id === $company->id);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, Company $company): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return $user->hasRole('super_admin');
    }
}
