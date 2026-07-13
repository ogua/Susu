<?php

namespace App\Actions\Loans;

use App\Enums\ClientOrigin;
use App\Enums\LoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
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
 * Disburses an approved loan: posts Dr loan-receivable / Cr branch-cash (net
 * of any origination fee, which is booked straight to fee income instead of
 * being added to what the customer repays) and persists the full repayment
 * schedule. The receivable's balance from here on IS the loan's outstanding
 * balance — repayments simply credit it down.
 */
class DisburseLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private ScheduleGenerator $schedule,
    ) {}

    public function execute(Loan $loan, User $disbursedBy): Loan
    {
        if ($loan->status !== LoanStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'Only approved loans can be disbursed.']);
        }

        return DB::transaction(function () use ($loan, $disbursedBy): Loan {
            $disbursedAt = now();

            $schedule = $this->schedule->generate(
                $loan->principal_amount,
                $loan->interest_rate_bps,
                $loan->term_period_count,
                $loan->interest_method,
                $loan->repayment_frequency,
                $disbursedAt,
            );

            $totalInterest = array_sum(array_map(
                fn ($installment): int => $installment->interestDue,
                $schedule,
            ));
            $totalRepayable = $loan->principal_amount + $totalInterest;
            $netCash = $loan->principal_amount - $loan->origination_fee_amount;
            $receivableAccount = $this->chart->loanReceivable($loan);

            $lines = [
                ['account' => $receivableAccount, 'debit' => $loan->principal_amount],
                ['account' => $this->chart->branchCash($loan->branch), 'credit' => $netCash],
            ];
            if ($loan->origination_fee_amount > 0) {
                $lines[] = ['account' => $this->chart->loanFeeIncome($loan->company), 'credit' => $loan->origination_fee_amount];
            }

            $this->ledger->post(new EntryData(
                company: $loan->company,
                type: TransactionType::Disbursement,
                lines: $lines,
                branch: $loan->branch,
                paymentMethod: PaymentMethod::Cash,
                origin: ClientOrigin::Web,
                recordedBy: $disbursedBy,
                recordedAt: $disbursedAt,
                description: "Loan disbursement {$loan->loan_number}",
                meta: [
                    'customer_id' => $loan->customer_id,
                    'loan_id' => $loan->id,
                    'amount' => $netCash,
                ],
            ));

            foreach ($schedule as $installment) {
                LoanInstallment::create([
                    'loan_id' => $loan->id,
                    'sequence' => $installment->sequence,
                    'due_date' => $installment->dueDate->toDateString(),
                    'principal_due' => $installment->principalDue,
                    'interest_due' => $installment->interestDue,
                ]);
            }

            $loan->forceFill([
                'receivable_account_id' => $receivableAccount->id,
                'total_interest' => $totalInterest,
                'total_repayable' => $totalRepayable,
                'outstanding_balance' => $totalRepayable,
                'status' => LoanStatus::Disbursed,
                'disbursed_at' => $disbursedAt,
            ])->save();

            return $loan->fresh();
        });
    }
}
