<?php

namespace App\Actions\Loans;

use App\Enums\ClientOrigin;
use App\Enums\LoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Declares a disbursed loan's remaining balance uncollectible. A single
 * atomic transition, not partial — a loan is written off in full, mirroring
 * how every other loan decision (approve/reject/disburse) is one clean
 * status change. Recording a repayment afterward already correctly fails via
 * RecordLoanRepaymentAction's existing `status !== Disbursed` guard — no
 * change needed there.
 *
 * An amount of the borrower's own savings can optionally be applied against
 * the outstanding balance first (via ApplySavingsToLoanAction), reducing what
 * ends up booked as bad debt.
 *
 * The bad-debt ledger entry only moves the loan's remaining PRINCIPAL (Dr bad
 * debt expense / Cr loan receivable) — the receivable account only ever holds
 * principal (interest/penalty are recognized as income solely when actually
 * collected, per RecordLoanRepaymentAction), so crediting it by the full
 * outstanding_balance (which includes unrealized interest/penalty) would
 * over-credit it negative. `write_off_amount` still snapshots the full
 * outstanding_balance for reporting — that's the real business loss even
 * though only the principal portion ever touched the general ledger.
 */
class WriteOffLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private ApplySavingsToLoanAction $applySavings,
    ) {}

    public function execute(
        Loan $loan,
        User $writtenOffBy,
        string $reason,
        ?SavingsAccount $savingsAccount = null,
        int $savingsAmountApplied = 0,
    ): Loan {
        if ($loan->status !== LoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed loans can be written off.']);
        }
        if ($loan->outstanding_balance <= 0) {
            throw ValidationException::withMessages(['status' => 'This loan has no outstanding balance to write off.']);
        }

        return DB::transaction(function () use ($loan, $writtenOffBy, $reason, $savingsAccount, $savingsAmountApplied): Loan {
            $writeOffAmount = $loan->outstanding_balance;

            if ($savingsAccount !== null && $savingsAmountApplied > 0) {
                if ($savingsAccount->customer_id !== $loan->customer_id) {
                    throw ValidationException::withMessages(['savings_account_id' => 'This savings account does not belong to the borrower.']);
                }
                if ($savingsAmountApplied > $writeOffAmount) {
                    throw ValidationException::withMessages(['savings_amount_applied' => 'Cannot apply more than the outstanding balance.']);
                }

                // Must run before the principal read below — this posting
                // already credits the receivable account, so refreshing it
                // afterward naturally yields the reduced residual. Reordering
                // this would double-count the applied amount in the bad-debt
                // entry.
                $this->applySavings->execute(
                    savingsAccount: $savingsAccount,
                    receivableAccount: $loan->receivableAccount,
                    amount: $savingsAmountApplied,
                    appliedBy: $writtenOffBy,
                    transactionType: TransactionType::SavingsAppliedToLoanWriteOff,
                    description: "Savings applied to write-off {$loan->loan_number}",
                    meta: [
                        'customer_id' => $loan->customer_id,
                        'loan_id' => $loan->id,
                        'amount' => $savingsAmountApplied,
                    ],
                );
            }

            $principalOutstanding = $loan->receivableAccount->refresh()->balance;

            if ($principalOutstanding > 0) {
                $this->ledger->post(new EntryData(
                    company: $loan->company,
                    type: TransactionType::WriteOff,
                    lines: [
                        ['account' => $this->chart->badDebtExpense($loan->company), 'debit' => $principalOutstanding],
                        ['account' => $loan->receivableAccount, 'credit' => $principalOutstanding],
                    ],
                    branch: $loan->branch,
                    paymentMethod: PaymentMethod::Cash,
                    origin: ClientOrigin::Web,
                    recordedBy: $writtenOffBy,
                    recordedAt: now(),
                    description: "Loan write-off {$loan->loan_number}",
                    meta: [
                        'customer_id' => $loan->customer_id,
                        'loan_id' => $loan->id,
                        'amount' => $principalOutstanding,
                    ],
                ));
            }

            $loan->forceFill([
                'status' => LoanStatus::WrittenOff,
                'outstanding_balance' => 0,
                'written_off_at' => now(),
                'write_off_reason' => $reason,
                'write_off_amount' => $writeOffAmount,
                'write_off_savings_account_id' => $savingsAccount?->id,
                'write_off_savings_applied' => $savingsAccount !== null ? $savingsAmountApplied : 0,
                'approved_by' => $writtenOffBy->id,
            ])->save();

            return $loan->fresh();
        });
    }
}
