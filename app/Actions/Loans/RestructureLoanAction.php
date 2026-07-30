<?php

namespace App\Actions\Loans;

use App\Enums\ClientOrigin;
use App\Enums\LoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Services\Loans\ScheduleGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a disbursed loan whose repayment schedule isn't working and opens a
 * new linked loan for the same customer, carrying over the old loan's
 * outstanding PRINCIPAL (interest/penalty are never rolled over — they're
 * recognized as income only when actually collected, same discipline as
 * WriteOffLoanAction) onto a fresh schedule under a (possibly different)
 * LoanProduct's terms. No fresh cash changes hands here — see TopUpLoanAction
 * for that. The new loan is created directly at Disbursed rather than via
 * Apply/Approve/Disburse: those enforce fresh-application validations
 * (product min/max bounds, client_reference idempotency) that don't apply to
 * a manager-driven consolidation whose amount is dictated by the old balance,
 * not a customer request.
 */
class RestructureLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private ScheduleGenerator $schedule,
    ) {}

    public function execute(
        Loan $loan,
        User $restructuredBy,
        LoanProduct $newProduct,
        string $reason,
        ?string $newLoanClientReference = null,
    ): Loan {
        // Offline clients (desktop) generate the new loan's id themselves;
        // checking this first — before the status guard below — makes a
        // retried sync op idempotent even though $loan is no longer
        // Disbursed by the time the retry lands (mirrors ApplyForLoanAction).
        if ($newLoanClientReference !== null) {
            $existing = Loan::where('client_reference', $newLoanClientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($loan->status !== LoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed loans can be restructured.']);
        }
        if ($newProduct->company_id !== $loan->company_id) {
            throw ValidationException::withMessages(['loan_product_id' => 'This product is not available for this loan.']);
        }
        if (! $newProduct->is_active) {
            throw ValidationException::withMessages(['loan_product_id' => 'This loan product is no longer offered.']);
        }

        $principalOutstanding = $loan->receivableAccount->refresh()->balance;
        if ($principalOutstanding <= 0) {
            throw ValidationException::withMessages(['status' => 'Nothing to restructure — outstanding principal is already zero.']);
        }

        return DB::transaction(function () use ($loan, $restructuredBy, $newProduct, $reason, $principalOutstanding, $newLoanClientReference): Loan {
            $restructuredAt = now();
            $refinanceAmount = $loan->outstanding_balance;

            $newLoan = new Loan([
                'company_id' => $loan->company_id,
                'branch_id' => $loan->branch_id,
                'customer_id' => $loan->customer_id,
                'loan_product_id' => $newProduct->id,
                'savings_account_id' => $loan->savings_account_id,
                'agent_id' => $loan->agent_id,
                'loan_number' => $this->nextLoanNumber($loan->branch),
                'principal_amount' => $principalOutstanding,
                'interest_method' => $newProduct->interest_method,
                'interest_rate_bps' => $newProduct->interest_rate_bps,
                'term_period_count' => $newProduct->term_period_count,
                'repayment_frequency' => $newProduct->repayment_frequency,
                'origination_fee_amount' => $newProduct->origination_fee_amount,
                'penalty_rate_bps' => $newProduct->penalty_rate_bps,
                'grace_period_days' => $newProduct->grace_period_days,
                'total_interest' => 0,
                'total_repayable' => 0,
                'outstanding_balance' => 0,
                'status' => LoanStatus::Applied,
                'guarantor_name' => $loan->guarantor_name,
                'guarantor_phone' => $loan->guarantor_phone,
                'previous_loan_id' => $loan->id,
                'rolled_over_amount' => $principalOutstanding,
                'client_reference' => $newLoanClientReference,
                'applied_at' => $restructuredAt,
            ]);
            if ($newLoanClientReference !== null) {
                $newLoan->forceFill(['id' => $newLoanClientReference]);
            }
            $newLoan->save();

            $schedule = $this->schedule->generate(
                $newLoan->principal_amount,
                $newLoan->interest_rate_bps,
                $newLoan->term_period_count,
                $newLoan->interest_method,
                $newLoan->repayment_frequency,
                $restructuredAt,
            );

            $totalInterest = array_sum(array_map(
                fn ($installment): int => $installment->interestDue,
                $schedule,
            ));
            $totalRepayable = $newLoan->principal_amount + $totalInterest;
            $newReceivable = $this->chart->loanReceivable($newLoan);

            $this->ledger->post(new EntryData(
                company: $loan->company,
                type: TransactionType::LoanRestructure,
                lines: [
                    ['account' => $newReceivable, 'debit' => $newLoan->principal_amount],
                    ['account' => $loan->receivableAccount, 'credit' => $principalOutstanding],
                ],
                branch: $loan->branch,
                paymentMethod: PaymentMethod::Cash,
                origin: ClientOrigin::Web,
                recordedBy: $restructuredBy,
                recordedAt: $restructuredAt,
                description: "Loan restructure {$loan->loan_number} -> {$newLoan->loan_number}",
                meta: [
                    'customer_id' => $loan->customer_id,
                    'previous_loan_id' => $loan->id,
                    'new_loan_id' => $newLoan->id,
                    'amount' => $principalOutstanding,
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
                'approved_at' => $restructuredAt,
                'disbursed_at' => $restructuredAt,
                'approved_by' => $restructuredBy->id,
            ])->save();

            $loan->forceFill([
                'status' => LoanStatus::Refinanced,
                'outstanding_balance' => 0,
                'refinanced_at' => $restructuredAt,
                'refinance_type' => 'restructure',
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
