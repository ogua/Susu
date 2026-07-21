<?php

namespace App\Services\Ledger;

use App\Enums\LedgerAccountType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Group;
use App\Models\GroupLoan;
use App\Models\LedgerAccount;
use App\Models\Loan;
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

    /**
     * Money confirmed paid via mobile money, pending settlement from Paystack
     * to the company's bank account. Kept separate from agentCash() because
     * the agent never physically holds this money — conflating the two would
     * corrupt the day-close cash reconciliation (agentCash's balance).
     */
    public function momoClearing(Company $company): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'MOMO-CLEARING'],
            [
                'name' => 'Mobile Money Clearing',
                'type' => LedgerAccountType::Asset,
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

    /** Principal + interest a customer owes on a disbursed loan — an asset to the company. */
    public function loanReceivable(Loan $loan): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $loan->company_id, 'code' => 'LN-'.$loan->loan_number],
            [
                'branch_id' => $loan->branch_id,
                'name' => 'Loan '.$loan->loan_number,
                'type' => LedgerAccountType::Asset,
                'accountable_type' => Loan::class,
                'accountable_id' => $loan->id,
                'is_system' => true,
            ],
        );
    }

    public function loanInterestIncome(Company $company): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $company->id, 'code' => '4200-LNINT'],
            [
                'name' => 'Loan Interest Income',
                'type' => LedgerAccountType::Income,
                'is_system' => true,
            ],
        );
    }

    public function loanFeeIncome(Company $company): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $company->id, 'code' => '4300-LNFEE'],
            [
                'name' => 'Loan Fee Income',
                'type' => LedgerAccountType::Income,
                'is_system' => true,
            ],
        );
    }

    public function loanPenaltyIncome(Company $company): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $company->id, 'code' => '4400-LNPEN'],
            [
                'name' => 'Loan Penalty Income',
                'type' => LedgerAccountType::Income,
                'is_system' => true,
            ],
        );
    }

    /**
     * Principal + interest a loan group jointly owes on a disbursed group
     * loan — an asset to the company, same direction as loanReceivable().
     * Explicitly NOT the same direction as groupLiability() below, which is a
     * liability for pooled susu savings — don't conflate the two.
     */
    public function groupLoanReceivable(GroupLoan $groupLoan): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $groupLoan->company_id, 'code' => 'GLN-'.$groupLoan->loan_number],
            [
                'branch_id' => $groupLoan->branch_id,
                'name' => 'Group Loan '.$groupLoan->loan_number,
                'type' => LedgerAccountType::Asset,
                'accountable_type' => GroupLoan::class,
                'accountable_id' => $groupLoan->id,
                'is_system' => true,
            ],
        );
    }

    /**
     * Pooled but not-yet-paid-out contributions for one susu group's current
     * round — a liability to whichever member is due the payout.
     */
    public function groupLiability(Group $group): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $group->company_id, 'code' => 'GRP-'.$group->code],
            [
                'branch_id' => $group->branch_id,
                'name' => 'Group '.$group->name,
                'type' => LedgerAccountType::Liability,
                'accountable_type' => Group::class,
                'accountable_id' => $group->id,
                'is_system' => true,
            ],
        );
    }

    /**
     * The remaining balance of a loan/group loan a manager has declared
     * uncollectible — recognized as a loss, not a form of income. Shared
     * across all loans of a company, same shape as loanInterestIncome() etc.
     */
    public function badDebtExpense(Company $company): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $company->id, 'code' => '5100-BADDEBT'],
            [
                'name' => 'Bad Debt Expense',
                'type' => LedgerAccountType::Expense,
                'is_system' => true,
            ],
        );
    }

    /** Withheld from a target-savings withdrawal made before the account's matures_at date. */
    public function earlyWithdrawalPenaltyIncome(Company $company): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['company_id' => $company->id, 'code' => '4500-EWPEN'],
            [
                'name' => 'Early Withdrawal Penalty Income',
                'type' => LedgerAccountType::Income,
                'is_system' => true,
            ],
        );
    }
}
