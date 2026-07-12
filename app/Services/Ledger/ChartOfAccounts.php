<?php

namespace App\Services\Ledger;

use App\Enums\LedgerAccountType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\SavingsAccount;
use App\Models\User;

/**
 * Resolves (creating on first use) the system and entity sub-accounts every
 * posting pattern needs. Codes are unique per company.
 */
class ChartOfAccounts
{
    public function branchCash(Branch $branch): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $branch->company_id, 'code' => 'CASH-'.($branch->code ?? substr($branch->id, 0, 8))],
            [
                'branch_id' => $branch->id,
                'name' => $branch->name.' Cash',
                'type' => LedgerAccountType::Asset,
                'is_system' => true,
            ],
        );
    }

    public function commissionIncome(Company $company): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $company->id, 'code' => '4100-COMM'],
            [
                'name' => 'Susu Commission Income',
                'type' => LedgerAccountType::Income,
                'is_system' => true,
            ],
        );
    }

    /** The agent's cash-in-hand: expected cash for reconciliation is its balance. */
    public function agentCash(User $agent): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $agent->company_id, 'code' => 'AGT-'.substr($agent->id, 0, 8)],
            [
                'branch_id' => $agent->branch_id,
                'name' => $agent->name.' Cash In Hand',
                'type' => LedgerAccountType::Asset,
                'accountable_type' => User::class,
                'accountable_id' => $agent->id,
                'is_system' => true,
            ],
        );
    }

    /** The customer's savings balance is the company's liability to them. */
    public function savingsLiability(SavingsAccount $account): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $account->company_id, 'code' => 'SAV-'.$account->account_number],
            [
                'branch_id' => $account->branch_id,
                'name' => 'Savings '.$account->account_number,
                'type' => LedgerAccountType::Liability,
                'accountable_type' => SavingsAccount::class,
                'accountable_id' => $account->id,
                'is_system' => true,
            ],
        );
    }
}
