<?php

namespace App\Actions\Groups;

use App\Enums\GroupStatus;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Validation\ValidationException;

/** Membership is fixed once a group activates — rotation math depends on it. */
class AddGroupMemberAction
{
    public function execute(Group $group, Customer $customer, int $rotationPosition): GroupMember
    {
        if ($group->status !== GroupStatus::Draft) {
            throw ValidationException::withMessages(['group' => 'Members can only be added while the group is in draft.']);
        }
        if ($customer->company_id !== $group->company_id) {
            throw ValidationException::withMessages(['customer' => 'Customer not found in this company.']);
        }
        if ($group->members()->where('customer_id', $customer->id)->exists()) {
            throw ValidationException::withMessages(['customer' => 'This customer is already a member of the group.']);
        }
        if ($group->members()->where('rotation_position', $rotationPosition)->exists()) {
            throw ValidationException::withMessages(['rotation_position' => 'This rotation position is already taken.']);
        }

        return GroupMember::create([
            'group_id' => $group->id,
            'customer_id' => $customer->id,
            'rotation_position' => $rotationPosition,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
