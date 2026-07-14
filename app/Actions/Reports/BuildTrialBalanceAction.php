<?php

namespace App\Actions\Reports;

use App\Models\Company;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Collection;

/**
 * @phpstan-type TrialBalanceResult array{accounts: Collection<int, LedgerAccount>, totalDebits: int, totalCredits: int}
 */
class BuildTrialBalanceAction
{
    /**
     * Company-wide rather than branch-scoped: system income/expense accounts
     * (e.g. commission income) carry no branch_id, so a branch-scoped view
     * would silently omit them and appear artificially out of balance.
     *
     * @return TrialBalanceResult
     */
    public function execute(Company $company): array
    {
        $accounts = LedgerAccount::query()
            ->where('company_id', $company->id)
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        return [
            'accounts' => $accounts,
            'totalDebits' => $accounts->sum(fn (LedgerAccount $account) => $account->type->normalBalance() === 'debit' ? $account->balance : 0),
            'totalCredits' => $accounts->sum(fn (LedgerAccount $account) => $account->type->normalBalance() === 'credit' ? $account->balance : 0),
        ];
    }
}
