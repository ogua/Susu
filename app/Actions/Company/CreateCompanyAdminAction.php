<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the company super admin — the tenant-side account that logs into
 * the admin panel and adds the company's own staff (branch managers, field
 * agents, further company admins) through the Staff resource.
 */
class CreateCompanyAdminAction
{
    public function __construct(private readonly SyncCompanyAdminBranchAccessAction $syncBranchAccess) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string, password: string}  $data
     */
    public function execute(Company $company, array $data): User
    {
        if (! $company->branches()->exists()) {
            throw ValidationException::withMessages([
                'branch' => 'Add a branch to this company before creating its admin — admins log in through a branch.',
            ]);
        }

        return DB::transaction(function () use ($company, $data): User {
            $user = User::create([
                'company_id' => $company->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $user->assignRole('company_admin');
            $this->syncBranchAccess->execute($user);

            return $user->refresh();
        });
    }
}
