<?php

namespace App\Actions\Customers;

use App\Models\Customer;

class UpdateCustomerAction
{
    /**
     * @param  array<string, mixed>  $data  validated customer attributes
     */
    public function execute(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer;
    }
}
