<?php

namespace App\Actions\Staff;

use App\Actions\Staff\Concerns\ValidatesStaffAssignment;
use App\Models\Branch;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class UpdateStaffAction
{
    use ValidatesStaffAssignment;

    public function __construct(private readonly UserPolicy $policy) {}

    /**
     * @param  array{name: string, email: string, phone: ?string, password?: ?string, role: string, branch_ids: array<int, string>, is_active?: bool}  $data
     */
    public function execute(User $actor, User $target, Branch $tenant, array $data): User
    {
        if (! $this->policy->update($actor, $target)) {
            throw ValidationException::withMessages(['staff' => 'You are not allowed to manage this staff member.']);
        }

        $this->assertAssignableRole($actor, $this->policy, $data['role']);
        $branchIds = $this->assertManageableBranches($actor, $tenant, $data['branch_ids']);

        $target->update(Arr::only($data, ['name', 'email', 'phone', 'is_active']) + [
            'branch_id' => $branchIds->first(),
            ...(filled($data['password'] ?? null) ? ['password' => $data['password']] : []),
        ]);

        $target->syncRoles([$data['role']]);
        $target->branches()->sync($branchIds);

        return $target;
    }
}
