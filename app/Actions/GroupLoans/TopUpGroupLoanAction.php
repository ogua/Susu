<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\GroupLoanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Branch;
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
 * Mirrors TopUpLoanAction for a group loan: closes the old joint debt in good
 * standing and opens a new linked group loan whose principal is the old
 * loan's outstanding PRINCIPAL plus a manager-entered top-up amount of fresh
 * cash, re-splitting the new principal across the loan group's currently
 * active members (same re-check as DisburseGroupLoanAction/
 * RestructureGroupLoanAction — membership can drift since the original
 * disbursement).
 */
class TopUpGroupLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private ScheduleGenerator $schedule,
    ) {}

    public function execute(
        GroupLoan $groupLoan,
        User $toppedUpBy,
        int $topUpAmount,
        string $reason,
        ?string $newGroupLoanClientReference = null,
    ): GroupLoan {
        if ($newGroupLoanClientReference !== null) {
            $existing = GroupLoan::where('client_reference', $newGroupLoanClientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($groupLoan->status !== GroupLoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed group loans can be topped up.']);
        }
        if ($topUpAmount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The top-up amount must be greater than zero.']);
        }
        if ($groupLoan->installments()->where('status', InstallmentStatus::Overdue)->exists()) {
            throw ValidationException::withMessages(['status' => 'This group loan has an overdue installment and is not eligible for a top-up.']);
        }

        $members = $groupLoan->loanGroup->members()->where('status', 'active')->orderBy('joined_at')->orderBy('id')->get();
        if ($members->count() < 2) {
            throw ValidationException::withMessages(['loan_group' => 'A group loan needs at least 2 active members to top up.']);
        }

        $principalOutstanding = $groupLoan->receivableAccount->refresh()->balance;

        return DB::transaction(function () use ($groupLoan, $toppedUpBy, $topUpAmount, $reason, $principalOutstanding, $members, $newGroupLoanClientReference): GroupLoan {
            $toppedUpAt = now();
            $refinanceAmount = $groupLoan->outstanding_balance;
            $newPrincipal = $principalOutstanding + $topUpAmount;

            $newGroupLoan = new GroupLoan([
                'company_id' => $groupLoan->company_id,
                'branch_id' => $groupLoan->branch_id,
                'loan_group_id' => $groupLoan->loan_group_id,
                'loan_product_id' => $groupLoan->loan_product_id,
                'agent_id' => $groupLoan->agent_id,
                'loan_number' => $this->nextLoanNumber($groupLoan->branch),
                'principal_amount' => $newPrincipal,
                'interest_method' => $groupLoan->interest_method,
                'interest_rate_bps' => $groupLoan->interest_rate_bps,
                'term_period_count' => $groupLoan->term_period_count,
                'repayment_frequency' => $groupLoan->repayment_frequency,
                'origination_fee_amount' => $groupLoan->origination_fee_amount,
                'penalty_rate_bps' => $groupLoan->penalty_rate_bps,
                'grace_period_days' => $groupLoan->grace_period_days,
                'total_interest' => 0,
                'total_repayable' => 0,
                'outstanding_balance' => 0,
                'status' => GroupLoanStatus::Applied,
                'previous_group_loan_id' => $groupLoan->id,
                'rolled_over_amount' => $principalOutstanding,
                'client_reference' => $newGroupLoanClientReference,
                'applied_at' => $toppedUpAt,
            ]);
            if ($newGroupLoanClientReference !== null) {
                $newGroupLoan->forceFill(['id' => $newGroupLoanClientReference]);
            }
            $newGroupLoan->save();

            $schedule = $this->schedule->generate(
                $newGroupLoan->principal_amount,
                $newGroupLoan->interest_rate_bps,
                $newGroupLoan->term_period_count,
                $newGroupLoan->interest_method,
                $newGroupLoan->repayment_frequency,
                $toppedUpAt,
            );

            $totalInterest = array_sum(array_map(
                fn ($installment): int => $installment->interestDue,
                $schedule,
            ));
            $totalRepayable = $newGroupLoan->principal_amount + $totalInterest;
            $newReceivable = $this->chart->groupLoanReceivable($newGroupLoan);
            $netCash = $topUpAmount - $newGroupLoan->origination_fee_amount;

            $lines = [
                ['account' => $newReceivable, 'debit' => $newGroupLoan->principal_amount],
            ];
            if ($principalOutstanding > 0) {
                $lines[] = ['account' => $groupLoan->receivableAccount, 'credit' => $principalOutstanding];
            }
            $lines[] = ['account' => $this->chart->branchCash($groupLoan->branch), 'credit' => $netCash];
            if ($newGroupLoan->origination_fee_amount > 0) {
                $lines[] = ['account' => $this->chart->loanFeeIncome($groupLoan->company), 'credit' => $newGroupLoan->origination_fee_amount];
            }

            $this->ledger->post(new EntryData(
                company: $groupLoan->company,
                type: TransactionType::GroupLoanTopUp,
                lines: $lines,
                branch: $groupLoan->branch,
                paymentMethod: PaymentMethod::Cash,
                origin: ClientOrigin::Web,
                recordedBy: $toppedUpBy,
                recordedAt: $toppedUpAt,
                description: "Group loan top-up {$groupLoan->loan_number} -> {$newGroupLoan->loan_number}",
                meta: [
                    'loan_group_id' => $groupLoan->loan_group_id,
                    'previous_group_loan_id' => $groupLoan->id,
                    'new_group_loan_id' => $newGroupLoan->id,
                    'amount' => $netCash,
                ],
            ));

            $memberCount = $members->count();
            $sharePerMember = intdiv($newGroupLoan->principal_amount, $memberCount);
            $shareRemainder = $newGroupLoan->principal_amount - ($sharePerMember * $memberCount);

            foreach ($members->values() as $index => $member) {
                $share = $sharePerMember + ($index === $memberCount - 1 ? $shareRemainder : 0);

                GroupLoanBorrower::create([
                    'group_loan_id' => $newGroupLoan->id,
                    'loan_group_member_id' => $member->id,
                    'customer_id' => $member->customer_id,
                    'share_principal' => $share,
                    'share_outstanding' => $share,
                ]);
            }

            foreach ($schedule as $installment) {
                GroupLoanInstallment::create([
                    'group_loan_id' => $newGroupLoan->id,
                    'sequence' => $installment->sequence,
                    'due_date' => $installment->dueDate->toDateString(),
                    'principal_due' => $installment->principalDue,
                    'interest_due' => $installment->interestDue,
                ]);
            }

            $newGroupLoan->forceFill([
                'receivable_account_id' => $newReceivable->id,
                'total_interest' => $totalInterest,
                'total_repayable' => $totalRepayable,
                'outstanding_balance' => $totalRepayable,
                'member_count_at_disbursement' => $memberCount,
                'status' => GroupLoanStatus::Disbursed,
                'approved_at' => $toppedUpAt,
                'disbursed_at' => $toppedUpAt,
                'approved_by' => $toppedUpBy->id,
            ])->save();

            $groupLoan->forceFill([
                'status' => GroupLoanStatus::Refinanced,
                'outstanding_balance' => 0,
                'refinanced_at' => $toppedUpAt,
                'refinance_type' => 'top_up',
                'refinance_reason' => $reason,
                'refinance_amount' => $refinanceAmount,
            ])->save();

            return $newGroupLoan->fresh();
        });
    }

    /** G7 numbering, mirrors ApplyForGroupLoanAction::nextLoanNumber exactly. */
    private function nextLoanNumber(Branch $branch): string
    {
        $prefix = ($branch->code ?? strtoupper(substr($branch->id, 0, 4))).'-GL';
        $sequence = GroupLoan::where('branch_id', $branch->id)->count() + 1;

        while (GroupLoan::where('company_id', $branch->company_id)
            ->where('loan_number', $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT))
            ->exists()) {
            $sequence++;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
