<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates the login user for a customer (AD-7: customer ≠ user; the user row
 * exists only once mobile access is activated).
 */
class ProvisionCustomerLoginAction
{
    public function execute(Customer $customer, string $password): User
    {
        if ($customer->user_id !== null) {
            throw ValidationException::withMessages(['customer' => 'This customer already has a login.']);
        }

        return DB::transaction(function () use ($customer, $password): User {
            $user = User::create([
                'company_id' => $customer->company_id,
                'branch_id' => $customer->branch_id,
                'name' => $customer->fullName(),
                'email' => Str::lower(Str::slug($customer->fullName(), '.').'.'.substr($customer->id, 0, 6)).'@customer.susuapp',
                'phone' => $customer->phone,
                'password' => $password,
                'is_active' => true,
            ]);

            $user->assignRole('customer');
            $customer->forceFill(['user_id' => $user->id])->save();

            return $user;
        });
    }
}
