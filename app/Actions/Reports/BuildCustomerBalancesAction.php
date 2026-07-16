<?php

namespace App\Actions\Reports;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\SavingsAccount;

/**
 * As-at-now listing of every open (active or dormant) savings account in the
 * branch with its balance — closed accounts hold no customer money and are
 * excluded. No date range: balances are only meaningful as of now.
 *
 * @phpstan-type CustomerBalancesResult array{
 *     accounts: \Illuminate\Database\Eloquent\Collection<int, SavingsAccount>,
 *     totalBalance: int,
 * }
 */
class BuildCustomerBalancesAction
{
    /**
     * @return CustomerBalancesResult
     */
    public function execute(Branch $branch): array
    {
        $accounts = SavingsAccount::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Dormant])
            ->with(['customer', 'product', 'agent'])
            ->orderBy('account_number')
            ->get();

        return [
            'accounts' => $accounts,
            'totalBalance' => (int) $accounts->sum('balance'),
        ];
    }
}
