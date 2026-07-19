<?php

namespace App\Actions\Customers\Concerns;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * Reconciles nested Repeater arrays (create/update/delete-by-id) against a
 * Customer's child records. The wizard's Identification/Beneficiary/Family
 * repeaters aren't relationship-bound (see CreateCustomerAction's class doc
 * for why), so this reconciliation has to happen explicitly here instead of
 * Filament's saveRelationships() doing it for us.
 */
trait SyncsCustomerChildRecords
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncIdentifications(Customer $customer, array $rows): void
    {
        $this->syncChildRecords($customer->identifications(), $rows, [
            'id_type', 'id_number', 'issue_date', 'expiry_date', 'description', 'is_primary',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncBeneficiaries(Customer $customer, array $rows): void
    {
        $this->syncChildRecords($customer->beneficiaries(), $rows, [
            'name', 'relationship', 'amount_of_legacy', 'phone', 'address', 'town', 'county', 'state_region',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncFamilyMembers(Customer $customer, array $rows): void
    {
        $this->syncChildRecords($customer->familyMembers(), $rows, [
            'name', 'relationship', 'contact_phone', 'occupation',
        ]);
    }

    /**
     * Mirrors whichever identification row is flagged is_primary (or the
     * first row if none is flagged) onto the customer's legacy flat
     * id_type/id_number columns, so the existing API v1/mobile/desktop/report
     * contract keeps working unmodified. No-ops if the customer has no
     * identification rows (e.g. created via the legacy flat fields).
     */
    protected function applyPrimaryIdentification(Customer $customer): void
    {
        $primary = $customer->identifications()->where('is_primary', true)->first()
            ?? $customer->identifications()->oldest()->first();

        if ($primary === null) {
            return;
        }

        $customer->forceFill([
            'id_type' => $primary->id_type,
            'id_number' => $primary->id_number,
        ])->saveQuietly();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $allowedFields
     */
    private function syncChildRecords(HasMany $relation, array $rows, array $allowedFields): void
    {
        $existing = $relation->get()->keyBy('id');
        $keptIds = [];

        foreach ($rows as $row) {
            $attributes = Arr::only($row, $allowedFields);
            $id = $row['id'] ?? null;

            if ($id !== null && $existing->has($id)) {
                $existing->get($id)->update($attributes);
                $keptIds[] = $id;

                continue;
            }

            $keptIds[] = $relation->create($attributes)->id;
        }

        $relation->whereNotIn('id', $keptIds)->delete();
    }
}
