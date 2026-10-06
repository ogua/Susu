<?php

namespace App\Actions\Company;

use App\Actions\Staff\IssueTemporaryPasswordAction;
use App\Actions\Staff\SendStaffCredentialsAction;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the company super admin — the tenant-side account that logs into
 * the admin panel and adds the company's own staff (branch managers, field
 * agents, further company admins) through the Staff resource.
 *
 * The password is temporary (generated when not given): the admin receives
 * it by email/SMS and must choose their own at first sign-in.
 */
class CreateCompanyAdminAction
{
    public function __construct(
        private readonly SyncCompanyAdminBranchAccessAction $syncBranchAccess,
        private readonly SendStaffCredentialsAction $sendCredentials,
        private readonly PlanLimits $planLimits,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string, password?: ?string}  $data
     */
    public function execute(Company $company, array $data): User
    {
        if (! $company->branches()->exists()) {
            throw ValidationException::withMessages([
                'branch' => 'Add a branch to this company before creating its admin — admins log in through a branch.',
            ]);
        }

        $this->planLimits->assertCanAdd($company, 'staff');

        $password = filled($data['password'] ?? null) ? $data['password'] : IssueTemporaryPasswordAction::generatePassword();

        return DB::transaction(function () use ($company, $data, $password): User {
            $user = User::create([
                'company_id' => $company->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $user->assignRole('company_admin');
            $this->syncBranchAccess->execute($user);
            $this->sendCredentials->execute($user, $password);

            return $user->refresh();
        });
    }
}
