<?php

namespace App\Actions\Customers;

use App\Actions\Customers\Concerns\SyncsCustomerChildRecords;
use App\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateCustomerAction
{
    use SyncsCustomerChildRecords;

    /**
     * @param  array<string, mixed>  $data  validated customer attributes
     */
    public function execute(Customer $customer, array $data): Customer
    {
        $identifications = Arr::pull($data, 'identifications');
        $beneficiaries = Arr::pull($data, 'beneficiaries');
        $familyMembers = Arr::pull($data, 'family_members');

        DB::transaction(function () use ($customer, $data, $identifications, $beneficiaries, $familyMembers): void {
            $customer->update($data);

            if ($identifications !== null) {
                $this->syncIdentifications($customer, $identifications);
            }
            if ($beneficiaries !== null) {
                $this->syncBeneficiaries($customer, $beneficiaries);
            }
            if ($familyMembers !== null) {
                $this->syncFamilyMembers($customer, $familyMembers);
            }
            if ($identifications !== null) {
                $this->applyPrimaryIdentification($customer);
            }
        });

        return $customer;
    }
}
