<?php

namespace App\Actions\Company;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Brings a new tenant onto the platform in one step: the company, its first
 * branch and its company super admin. All three are required for anyone at
 * the company to log in, so they are created together or not at all.
 */
class OnboardCompanyAction
{
    public function __construct(
        private readonly CreateBranchAction $createBranch,
        private readonly CreateCompanyAdminAction $createCompanyAdmin,
    ) {}

    /**
     * @param  array<string, mixed>  $company  Company attributes (see Company::$fillable).
     * @param  array{name: string, slug: string, code?: ?string, address?: ?string, contact_phone?: ?string, contact_email?: ?string}  $branch
     * @param  array{name: string, email: string, phone?: ?string, password: string}  $admin
     */
    public function execute(array $company, array $branch, array $admin): Company
    {
        return DB::transaction(function () use ($company, $branch, $admin): Company {
            $record = Company::create($company);

            $this->createBranch->execute($record, $branch);
            $this->createCompanyAdmin->execute($record, $admin);

            return $record;
        });
    }
}
