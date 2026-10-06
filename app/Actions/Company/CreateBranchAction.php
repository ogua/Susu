<?php

namespace App\Actions\Company;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens a branch for a company and grants every existing company admin
 * access to it — without the branch_users rows they could not switch into
 * the new branch in the admin panel even though the API already lets them.
 */
class CreateBranchAction
{
    /**
     * @param  array{name: string, slug: string, code?: ?string, address?: ?string, contact_phone?: ?string, contact_email?: ?string}  $data
     */
    public function execute(Company $company, array $data): Branch
    {
        return DB::transaction(function () use ($company, $data): Branch {
            $branch = $company->branches()->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'code' => $data['code'] ?? null,
                'address' => $data['address'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
            ]);

            $companyAdminIds = User::role('company_admin')
                ->where('company_id', $company->id)
                ->pluck('id');

            $branch->users()->syncWithoutDetaching($companyAdminIds);

            return $branch;
        });
    }
}
