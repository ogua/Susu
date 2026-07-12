<?php

namespace App\Actions\Customers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registers a customer (field or back office). Idempotent on
 * client_reference for offline agent registrations.
 */
class CreateCustomerAction
{
    /**
     * @param  array<string, mixed>  $data  validated customer attributes
     */
    public function execute(User $registeredBy, Branch $branch, array $data, ?string $clientReference = null): Customer
    {
        if ($clientReference !== null) {
            $existing = Customer::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($data, $branch, $registeredBy, $clientReference): Customer {
            $customer = new Customer($data + [
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'customer_code' => $this->nextCustomerCode($branch),
                'client_reference' => $clientReference,
                'registered_by' => $registeredBy->id,
                'status' => 'active',
            ]);

            // Offline clients (mobile/desktop) generate this UUID themselves; using it
            // as the primary key too (id isn't mass-assignable, hence forceFill) means
            // the record's identity matches across every client that created it and
            // the server, so later ops (e.g. account.open) referencing this
            // customer_id resolve correctly once synced. Must be set before the first
            // save() so HasUuids' creating() hook sees it and skips auto-generation.
            if ($clientReference !== null) {
                $customer->forceFill(['id' => $clientReference]);
            }

            $customer->save();

            return $customer;
        });
    }

    /**
     * G7 numbering: {branch_code}-C{sequence}. Codes are display identity —
     * UUIDs remain the real identity — so a retry loop absorbs rare races.
     */
    private function nextCustomerCode(Branch $branch): string
    {
        $prefix = ($branch->code ?? strtoupper(substr($branch->id, 0, 4))).'-C';
        $sequence = Customer::where('branch_id', $branch->id)->withTrashed()->count() + 1;

        while (Customer::where('company_id', $branch->company_id)
            ->where('customer_code', $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT))
            ->withTrashed()
            ->exists()) {
            $sequence++;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
