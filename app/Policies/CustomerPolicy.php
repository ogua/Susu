<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->sameCompany($user, $customer)
            && $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->sameCompany($user, $customer)
            && $user->hasRole(['company_admin', 'branch_manager', 'field_agent']);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->sameCompany($user, $customer) && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $this->delete($user, $customer);
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return $user->hasRole('super_admin');
    }

    /** Customers may activate mobile login only via their own back office. */
    public function provisionLogin(User $user, Customer $customer): bool
    {
        return $this->sameCompany($user, $customer) && $user->hasRole(['company_admin', 'branch_manager']);
    }

    /** Moving a customer between branches is a back-office decision. */
    public function transfer(User $user, Customer $customer): bool
    {
        return $this->sameCompany($user, $customer) && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function assignAgent(User $user, Customer $customer): bool
    {
        return $this->sameCompany($user, $customer) && $user->hasRole(['company_admin', 'branch_manager']);
    }

    private function sameCompany(User $user, Customer $customer): bool
    {
        return $user->company_id === $customer->company_id;
    }
}
