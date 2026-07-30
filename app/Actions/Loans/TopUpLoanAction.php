<?php

namespace App\Actions\Loans;

use App\Enums\ClientOrigin;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Services\Loans\ScheduleGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a disbursed loan in good standing and opens a new linked loan whose
 * principal is the old loan's outstanding PRINCIPAL (per WriteOffLoanAction's
 * receivable-only discipline) plus a manager-entered top-up amount of fresh
 * cash. Unlike RestructureLoanAction, the new loan reuses the OLD loan's own
 * terms rather than a chosen LoanProduct — a top-up is "more of the same
 * deal," not a renegotiation.
 */
class TopUpLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private ScheduleGenerator $schedule,
    ) {}

    public function execute(Loan $loan, User $toppedUpBy, int $topUpAmount, string $reason): Loan
    {
        if ($loan->status !== LoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed loans can be topped up.']);
        }
        if ($topUpAmount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The top-up amount must be greater than zero.']);
        }
        if ($loan->installments()->where('status', InstallmentStatus::Overdue)->exists()) {
            throw ValidationException::withMessages(['status' => 'This loan has an overdue installment and is not eligible for a top-up.']);
        }

        $principalOutstanding = $loan->receivableAccount->refresh()->balance;

        return DB::transaction(function () use ($loan, $toppedUpBy, $topUpAmount, $reason, $principalOutstanding): Loan {
            $toppedUpAt = now();
            $refinanceAmount = $loan->outstanding_balance;
            $newPrincipal = $principalOutstanding + $topUpAmount;

            $newLoan = new Loan([
                'company_id' => $loan->company_id,
                'branch_id' => $loan->branch_id,
                'customer_id' => $loan->customer_id,
                'loan_product_id' => $loan->loan_product_id,
                'savings_account_id' => $loan->savings_account_id,
                'agent_id' => $loan->agent_id,
                'loan_number' => $this->nextLoanNumber($loan->branch),
                'principal_amount' => $newPrincipal,
                'interest_method' => $loan->interest_method,
                'interest_rate_bps' => $loan->interest_rate_bps,
                'term_period_count' => $loan->term_period_count,
                'repayment_frequency' => $loan->repayment_frequency,
                'origination_fee_amount' => $loan->origination_fee_amount,
                'penalty_rate_bps' => $loan->penalty_rate_bps,
                'grace_period_days' => $loan->grace_period_days,
                'total_interest' => 0,
                'total_repayable' => 0,
                'outstanding_balance' => 0,
                'status' => LoanStatus::Applied,
                'guarantor_name' => $loan->guarantor_name,
                'guarantor_phone' => $loan->guarantor_phone,
                'previous_loan_id' => $loan->id,
                'rolled_over_amount' => $principalOutstanding,
                'applied_at' => $toppedUpAt,
            ]);
            $newLoan->save();

            $schedule = $this->schedule->generate(
                $newLoan->principal_amount,
                $newLoan->interest_rate_bps,
                $newLoan->term_period_count,
                $newLoan->interest_method,
                $newLoan->repayment_frequency,
                $toppedUpAt,
            );

            $totalInterest = array_sum(array_map(
                fn ($installment): int => $installment->interestDue,
                $schedule,
            ));
            $totalRepayable = $newLoan->principal_amount + $totalInterest;
            $newReceivable = $this->chart->loanReceivable($newLoan);
            $netCash = $topUpAmount - $newLoan->origination_fee_amount;

            $lines = [
                ['account' => $newReceivable, 'debit' => $newLoan->principal_amount],
            ];
            if ($principalOutstanding > 0) {
                $lines[] = ['account' => $loan->receivableAccount, 'credit' => $principalOutstanding];
            }
            $lines[] = ['account' => $this->chart->branchCash($loan->branch), 'credit' => $netCash];
            if ($newLoan->origination_fee_amount > 0) {
                $lines[] = ['account' => $this->chart->loanFeeIncome($loan->company), 'credit' => $newLoan->origination_fee_amount];
            }

            $this->ledger->post(new EntryData(
                company: $loan->company,
                type: TransactionType::LoanTopUp,
                lines: $lines,
                branch: $loan->branch,
                paymentMethod: PaymentMethod::Cash,
                origin: ClientOrigin::Web,
                recordedBy: $toppedUpBy,
                recordedAt: $toppedUpAt,
                description: "Loan top-up {$loan->loan_number} -> {$newLoan->loan_number}",
                meta: [
                    'customer_id' => $loan->customer_id,
                    'previous_loan_id' => $loan->id,
                    'new_loan_id' => $newLoan->id,
                    'amount' => $netCash,
                ],
            ));

            foreach ($schedule as $installment) {
                LoanInstallment::create([
                    'loan_id' => $newLoan->id,
                    'sequence' => $installment->sequence,
                    'due_date' => $installment->dueDate->toDateString(),
                    'principal_due' => $installment->principalDue,
                    'interest_due' => $installment->interestDue,
                ]);
            }

            $newLoan->forceFill([
                'receivable_account_id' => $newReceivable->id,
                'total_interest' => $totalInterest,
                'total_repayable' => $totalRepayable,
                'outstanding_balance' => $totalRepayable,
                'status' => LoanStatus::Disbursed,
                'approved_at' => $toppedUpAt,
                'disbursed_at' => $toppedUpAt,
                'approved_by' => $toppedUpBy->id,
            ])->save();

            $loan->forceFill([
                'status' => LoanStatus::Refinanced,
                'outstanding_balance' => 0,
                'refinanced_at' => $toppedUpAt,
                'refinance_type' => 'top_up',
                'refinance_reason' => $reason,
                'refinance_amount' => $refinanceAmount,
            ])->save();

            return $newLoan->fresh();
        });
    }

    /** G7 numbering, mirrors ApplyForLoanAction::nextLoanNumber exactly. */
    private function nextLoanNumber(Branch $branch): string
    {
        $prefix = ($branch->code ?? strtoupper(substr($branch->id, 0, 4))).'-L';
        $sequence = Loan::where('branch_id', $branch->id)->count() + 1;

        while (Loan::where('company_id', $branch->company_id)
            ->where('loan_number', $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT))
            ->exists()) {
            $sequence++;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
