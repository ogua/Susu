<?php

namespace App\Policies;

use App\Models\SavingsProduct;
use App\Models\User;

/** Products are company-level and managed only by company admins. */
class SavingsProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, SavingsProduct $product): bool
    {
        return $user->company_id === $product->company_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['super_admin', 'company_admin']);
    }

    public function update(User $user, SavingsProduct $product): bool
    {
        return $user->hasRole('super_admin')
            || ($user->hasRole('company_admin') && $user->company_id === $product->company_id);
    }

    public function delete(User $user, SavingsProduct $product): bool
    {
        return $this->update($user, $product);
    }

    public function restore(User $user, SavingsProduct $product): bool
    {
        return $this->update($user, $product);
    }

    public function forceDelete(User $user, SavingsProduct $product): bool
    {
        return $user->hasRole('super_admin');
    }
}
