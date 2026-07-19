<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Models\GroupLoanInstallment;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Services\Loans\ScheduleGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Disburses an approved group loan: splits the principal evenly across the
 * loan group's currently-active members (integer division, remainder to the
 * last member — same rounding convention ScheduleGenerator uses for periods,
 * applied here to members instead), posts one shared ledger entry, and
 * persists one shared repayment schedule. Membership can drift between apply
 * and disburse, so the >=2-active-member guard is re-checked here too.
 */
class DisburseGroupLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private ScheduleGenerator $schedule,
    ) {}

    public function execute(GroupLoan $groupLoan, User $disbursedBy): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'Only approved group loans can be disbursed.']);
        }

        $members = $groupLoan->loanGroup->members()->where('status', 'active')->orderBy('joined_at')->orderBy('id')->get();
        if ($members->count() < 2) {
            throw ValidationException::withMessages(['loan_group' => 'A group loan needs at least 2 active members to disburse.']);
        }

        return DB::transaction(function () use ($groupLoan, $disbursedBy, $members): GroupLoan {
            $disbursedAt = now();

            $schedule = $this->schedule->generate(
                $groupLoan->principal_amount,
                $groupLoan->interest_rate_bps,
                $groupLoan->term_period_count,
                $groupLoan->interest_method,
                $groupLoan->repayment_frequency,
                $disbursedAt,
            );

            $totalInterest = array_sum(array_map(
                fn ($installment): int => $installment->interestDue,
                $schedule,
            ));
            $totalRepayable = $groupLoan->principal_amount + $totalInterest;
            $netCash = $groupLoan->principal_amount - $groupLoan->origination_fee_amount;
            $receivableAccount = $this->chart->groupLoanReceivable($groupLoan);

            $lines = [
                ['account' => $receivableAccount, 'debit' => $groupLoan->principal_amount],
                ['account' => $this->chart->branchCash($groupLoan->branch), 'credit' => $netCash],
            ];
            if ($groupLoan->origination_fee_amount > 0) {
                $lines[] = ['account' => $this->chart->loanFeeIncome($groupLoan->company), 'credit' => $groupLoan->origination_fee_amount];
            }

            $this->ledger->post(new EntryData(
                company: $groupLoan->company,
                type: TransactionType::GroupLoanDisbursement,
                lines: $lines,
                branch: $groupLoan->branch,
                paymentMethod: PaymentMethod::Cash,
                origin: ClientOrigin::Web,
                recordedBy: $disbursedBy,
                recordedAt: $disbursedAt,
                description: "Group loan disbursement {$groupLoan->loan_number}",
                meta: [
                    // No customer_id — a group loan has no single customer.
                    'loan_group_id' => $groupLoan->loan_group_id,
                    'group_loan_id' => $groupLoan->id,
                    'amount' => $netCash,
                ],
            ));

            $memberCount = $members->count();
            $sharePerMember = intdiv($groupLoan->principal_amount, $memberCount);
            $shareRemainder = $groupLoan->principal_amount - ($sharePerMember * $memberCount);

            foreach ($members->values() as $index => $member) {
                $share = $sharePerMember + ($index === $memberCount - 1 ? $shareRemainder : 0);

                GroupLoanBorrower::create([
                    'group_loan_id' => $groupLoan->id,
                    'loan_group_member_id' => $member->id,
                    'customer_id' => $member->customer_id,
                    'share_principal' => $share,
                    'share_outstanding' => $share,
                ]);
            }

            foreach ($schedule as $installment) {
                GroupLoanInstallment::create([
                    'group_loan_id' => $groupLoan->id,
                    'sequence' => $installment->sequence,
                    'due_date' => $installment->dueDate->toDateString(),
                    'principal_due' => $installment->principalDue,
                    'interest_due' => $installment->interestDue,
                ]);
            }

            $groupLoan->forceFill([
                'receivable_account_id' => $receivableAccount->id,
                'total_interest' => $totalInterest,
                'total_repayable' => $totalRepayable,
                'outstanding_balance' => $totalRepayable,
                'member_count_at_disbursement' => $memberCount,
                'status' => GroupLoanStatus::Disbursed,
                'disbursed_at' => $disbursedAt,
            ])->save();

            return $groupLoan->fresh();
        });
    }
}
