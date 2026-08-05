<?php

namespace App\Actions\Staff;

use App\Actions\Staff\Concerns\ValidatesStaffAssignment;
use App\Models\Branch;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Validation\ValidationException;

class CreateStaffAction
{
    use ValidatesStaffAssignment;

    public function __construct(private readonly UserPolicy $policy) {}

    /**
     * @param  array{name: string, email: string, phone: ?string, password: string, role: string, branch_ids: array<int, string>, is_active?: bool}  $data
     */
    public function execute(User $actor, Branch $tenant, array $data): User
    {
        if (! $this->policy->create($actor)) {
            throw ValidationException::withMessages(['staff' => 'You are not allowed to add staff.']);
        }

        $this->assertAssignableRole($actor, $this->policy, $data['role']);
        $branchIds = $this->assertManageableBranches($actor, $tenant, $data['branch_ids']);

        $user = User::create([
            'company_id' => $tenant->company_id,
            'branch_id' => $branchIds->first(),
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $user->assignRole($data['role']);
        $user->branches()->sync($branchIds);

        return $user;
    }
}
