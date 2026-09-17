<?php

namespace App\Actions\GroupLoans;

use App\Actions\Loans\ApplySavingsToLoanAction;
use App\Enums\ClientOrigin;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Declares an active group loan's remaining balance uncollectible. An amount
 * of the borrower's own savings can optionally be applied against the
 * outstanding balance first (Dr savings liability / Cr receivable, via
 * ApplySavingsToLoanAction), then only the residual is recognized as bad
 * debt (Dr bad-debt expense / Cr receivable). Manager-tier only.
 */
class WriteOffGroupLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private ApplySavingsToLoanAction $applySavings,
    ) {}

    public function execute(
        GroupLoan $groupLoan,
        User $writtenOffBy,
        string $reason,
        ?SavingsAccount $savingsAccount = null,
        int $savingsAmountApplied = 0,
        ClientOrigin $origin = ClientOrigin::Web,
    ): GroupLoan {
        if ($groupLoan->status !== GroupLoanStatus::Active) {
            throw ValidationException::withMessages(['status' => 'Only an active group loan can be written off.']);
        }
        if ($groupLoan->outstanding_balance <= 0) {
            throw ValidationException::withMessages(['status' => 'This group loan has no outstanding balance to write off.']);
        }

        return DB::transaction(function () use ($groupLoan, $writtenOffBy, $reason, $savingsAccount, $savingsAmountApplied, $origin): GroupLoan {
            $outstanding = $groupLoan->outstanding_balance;
            $applied = 0;

            if ($savingsAccount !== null && $savingsAmountApplied > 0) {
                if ($savingsAccount->customer_id !== $groupLoan->customer_id) {
                    throw ValidationException::withMessages(['savings_account_id' => 'This savings account does not belong to the borrower.']);
                }
                if ($savingsAmountApplied > $outstanding) {
                    throw ValidationException::withMessages(['savings_amount_applied' => 'Cannot apply more than the outstanding balance.']);
                }

                $this->applySavings->execute(
                    savingsAccount: $savingsAccount,
                    receivableAccount: $groupLoan->receivableAccount,
                    amount: $savingsAmountApplied,
                    appliedBy: $writtenOffBy,
                    transactionType: TransactionType::SavingsAppliedToGroupLoanWriteOff,
                    description: "Savings applied to write-off {$groupLoan->loan_number}",
                    meta: [
                        'customer_id' => $groupLoan->customer_id,
                        'group_loan_id' => $groupLoan->id,
                        'amount' => $savingsAmountApplied,
                    ],
                    origin: $origin,
                );

                $applied = $savingsAmountApplied;
            }

            $residual = $outstanding - $applied;

            if ($residual > 0) {
                $this->ledger->post(new EntryData(
                    company: $groupLoan->company,
                    type: TransactionType::GroupLoanWriteOff,
                    lines: [
                        ['account' => $this->chart->badDebtExpense($groupLoan->company), 'debit' => $residual],
                        ['account' => $groupLoan->receivableAccount, 'credit' => $residual],
                    ],
                    branch: $groupLoan->branch,
                    paymentMethod: PaymentMethod::Internal,
                    origin: $origin,
                    recordedBy: $writtenOffBy,
                    recordedAt: now(),
                    description: "Group loan write-off {$groupLoan->loan_number}",
                    meta: [
                        'customer_id' => $groupLoan->customer_id,
                        'loan_group_id' => $groupLoan->loan_group_id,
                        'group_loan_id' => $groupLoan->id,
                        'amount' => $residual,
                    ],
                ));
            }

            $groupLoan->forceFill([
                'status' => GroupLoanStatus::WrittenOff,
                'outstanding_balance' => 0,
                'written_off_at' => now(),
                'write_off_reason' => $reason,
                'write_off_amount' => $outstanding,
                'write_off_savings_account_id' => $savingsAccount?->id,
                'write_off_savings_applied' => $applied,
            ])->save();

            return $groupLoan->fresh();
        });
    }
}
