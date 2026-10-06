<?php

namespace App\Actions\LoanGroups;

use App\Models\Branch;
use App\Models\LoanGroup;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Creates a customer (loan) group. Shared by the Filament create page, the
 * REST endpoint and the loan_group.create sync op; an offline client's
 * client_reference becomes the group's id so its later member and group-loan
 * ops resolve it.
 */
class CreateLoanGroupAction
{
    /**
     * @param  array{name: string, code: string}  $data
     */
    public function execute(User $createdBy, Branch $branch, array $data, ?string $clientReference = null): LoanGroup
    {
        if ($clientReference !== null) {
            $existing = LoanGroup::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        // Checked here rather than left to the unique index, so a clash is a
        // validation error and never a retryable sync failure.
        if (LoanGroup::withTrashed()->where('company_id', $branch->company_id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'A customer group with this code already exists.']);
        }

        $loanGroup = new LoanGroup([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'created_by' => $createdBy->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'is_active' => true,
        ]);
        if ($clientReference !== null) {
            $loanGroup->forceFill(['id' => $clientReference, 'client_reference' => $clientReference]);
        }
        $loanGroup->save();

        return $loanGroup;
    }
}
