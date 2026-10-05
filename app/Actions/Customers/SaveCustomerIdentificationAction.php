<?php

namespace App\Actions\Customers;

use App\Actions\Customers\Concerns\SyncsCustomerChildRecords;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Adds, edits or removes a single identification record (the modal flow on
 * the customer view/edit pages). Keeps exactly one row flagged primary and
 * mirrors it onto the customer's legacy flat id_type/id_number columns,
 * exactly like the bulk repeater sync does.
 */
class SaveCustomerIdentificationAction
{
    use SyncsCustomerChildRecords;

    private const FIELDS = ['id_type', 'id_number', 'issue_date', 'expiry_date', 'description', 'is_primary'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Customer $customer, array $data, ?CustomerIdentification $identification = null): CustomerIdentification
    {
        return DB::transaction(function () use ($customer, $data, $identification): CustomerIdentification {
            $attributes = Arr::only($data, self::FIELDS);

            $identification === null
                ? $identification = $customer->identifications()->create($attributes)
                : $identification->update($attributes);

            if ($identification->is_primary) {
                $customer->identifications()
                    ->whereKeyNot($identification->getKey())
                    ->update(['is_primary' => false]);
            }

            $this->applyPrimaryIdentification($customer);

            return $identification;
        });
    }

    public function delete(CustomerIdentification $identification): void
    {
        DB::transaction(function () use ($identification): void {
            $customer = $identification->customer;
            $identification->delete();
            $this->applyPrimaryIdentification($customer);
        });
    }
}
