<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\GroupLoanInstallment;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Services\Loans\PeriodicScheduleGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activates a draft group loan once its deposit is held: generates the
 * member's repayment schedule from their periodic amount, disburses the
 * principal (Dr receivable / Cr branch cash — no fee, no interest), and sets
 * the outstanding balance. Each member's loan is activated independently.
 */
class ActivateGroupLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private PeriodicScheduleGenerator $schedule,
    ) {}

    public function execute(GroupLoan $groupLoan, User $activatedBy, ClientOrigin $origin = ClientOrigin::Web): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only a draft group loan can be activated.']);
        }
        if ($groupLoan->deposit_status !== DepositStatus::Held) {
            throw ValidationException::withMessages(['deposit_status' => 'Record the security deposit before activating the loan.']);
        }

        return DB::transaction(function () use ($groupLoan, $activatedBy, $origin): GroupLoan {
            $activatedAt = now();

            $schedule = $this->schedule->generate(
                $groupLoan->principal_amount,
                $groupLoan->periodic_amount,
                $groupLoan->repayment_frequency,
                $groupLoan->start_date->copy(),
            );

            $receivableAccount = $this->chart->groupLoanReceivable($groupLoan);

            $this->ledger->post(new EntryData(
                company: $groupLoan->company,
                type: TransactionType::GroupLoanDisbursement,
                lines: [
                    ['account' => $receivableAccount, 'debit' => $groupLoan->principal_amount],
                    ['account' => $this->chart->branchCash($groupLoan->branch), 'credit' => $groupLoan->principal_amount],
                ],
                branch: $groupLoan->branch,
                paymentMethod: PaymentMethod::Cash,
                origin: $origin,
                recordedBy: $activatedBy,
                recordedAt: $activatedAt,
                description: "Group loan disbursement {$groupLoan->loan_number}",
                meta: [
                    'customer_id' => $groupLoan->customer_id,
                    'loan_group_id' => $groupLoan->loan_group_id,
                    'group_loan_id' => $groupLoan->id,
                    'amount' => $groupLoan->principal_amount,
                ],
            ));

            foreach ($schedule as $installment) {
                GroupLoanInstallment::create([
                    'group_loan_id' => $groupLoan->id,
                    'sequence' => $installment->sequence,
                    'due_date' => $installment->dueDate->toDateString(),
                    'amount_due' => $installment->principalDue,
                ]);
            }

            $groupLoan->forceFill([
                'receivable_account_id' => $receivableAccount->id,
                'outstanding_balance' => $groupLoan->principal_amount,
                'total_periods' => count($schedule),
                'status' => GroupLoanStatus::Active,
                'activated_by' => $activatedBy->id,
                'activated_at' => $activatedAt,
            ])->save();

            return $groupLoan->fresh();
        });
    }
}
