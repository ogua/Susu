<?php

namespace App\Actions\Groups;

use App\Enums\GroupStatus;
use App\Models\Branch;
use App\Models\Group;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft susu (ROSCA) group. Shared by the Filament create page and
 * the group.create sync op; an offline client's client_reference becomes the
 * group's id so its later member/activate/payout ops resolve it.
 */
class CreateGroupAction
{
    /**
     * @param  array{name: string, code: string, contribution_amount: int, frequency: string}  $data
     */
    public function execute(User $createdBy, Branch $branch, array $data, ?string $clientReference = null): Group
    {
        if ($clientReference !== null) {
            $existing = Group::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        // Checked here rather than left to the unique index, so a clash is a
        // validation error and never a retryable sync failure.
        if (Group::withTrashed()->where('company_id', $branch->company_id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'A group with this code already exists.']);
        }

        $group = new Group([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'created_by' => $createdBy->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'contribution_amount' => $data['contribution_amount'],
            'frequency' => $data['frequency'],
            'status' => GroupStatus::Draft,
        ]);
        if ($clientReference !== null) {
            $group->forceFill(['id' => $clientReference, 'client_reference' => $clientReference]);
        }
        $group->save();

        return $group;
    }
}
