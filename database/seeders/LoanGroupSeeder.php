<?php

namespace Database\Seeders;

use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds solidarity loan groups on every branch of the first company and
 * places every customer who isn't yet in a loan group into one on their own
 * branch (through AddLoanGroupMemberAction). Existing groups are topped up too.
 * Re-runnable: groups are matched by code and members by customer.
 */
class LoanGroupSeeder extends Seeder
{
    /** @var list<string> */
    private const GROUP_NAMES = [
        'Nkabom', 'Obaa Mmienu', 'Makola Traders', 'Ahotor', 'Abusua Pa', 'Nyame Bekyere',
        'Kaneshie Fishmongers', 'Ebenezer Artisans', 'Mmoa Nkoa', 'Adom Women', 'Asomdwee', 'Agape Farmers',
        'Onyame Adom', 'Yɛn Ara Asaase', 'Kpakpakpa Tailors', 'Sika Mpɔnkɔ',
    ];

    private const GROUPS_PER_BRANCH = 8;

    private const MAX_MEMBERS_PER_GROUP = 10;

    public function run(AddLoanGroupMemberAction $addMember): void
    {
        $company = Company::query()->oldest()->first();

        if (! $company) {
            $this->command?->warn('LoanGroupSeeder skipped: no company found. Run DemoSeeder first.');

            return;
        }

        $branches = Branch::query()->where('company_id', $company->id)->orderBy('created_at')->get();
        $groupsCreated = 0;
        $membersAdded = 0;

        foreach ($branches as $branchIndex => $branch) {
            $creator = User::query()
                ->where('branch_id', $branch->id)
                ->role(['branch_manager', 'field_agent'])
                ->first()
                ?? User::query()->where('company_id', $company->id)->role('company_admin')->first();

            for ($i = 0; $i < self::GROUPS_PER_BRANCH; $i++) {
                $name = self::GROUP_NAMES[($branchIndex * self::GROUPS_PER_BRANCH + $i) % count(self::GROUP_NAMES)];
                $code = sprintf('LG-%s-%02d', $branch->code ?? $branchIndex + 1, $i + 1);

                $group = LoanGroup::query()->firstOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    [
                        'branch_id' => $branch->id,
                        'created_by' => $creator?->id,
                        'name' => $name.' Group',
                        'is_active' => true,
                    ],
                );

                if ($group->wasRecentlyCreated) {
                    $groupsCreated++;
                }
            }

            $groups = LoanGroup::query()->where('branch_id', $branch->id)->where('is_active', true)->withCount('members')->get();

            $unassignedCustomers = Customer::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'active')
                ->whereNotIn('id', LoanGroupMember::query()->where('status', 'active')->select('customer_id'))
                ->inRandomOrder()
                ->get();

            foreach ($unassignedCustomers as $customer) {
                $group = $groups->where('members_count', '<', self::MAX_MEMBERS_PER_GROUP)->sortBy('members_count')->first();

                if (! $group) {
                    break;
                }

                $addMember->execute($group, $customer);
                $group->members_count++;
                $membersAdded++;
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d loan groups and added %d members across %d branch(es) of "%s".',
            $groupsCreated,
            $membersAdded,
            $branches->count(),
            $company->name,
        ));
    }
}
