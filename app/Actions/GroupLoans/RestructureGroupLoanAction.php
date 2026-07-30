<?php

namespace App\Actions\GroupLoans;

use App\Enums\ClientOrigin;
use App\Enums\GroupLoanStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Models\GroupLoanInstallment;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use App\Services\Loans\ScheduleGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors RestructureLoanAction for a group loan: closes the old joint debt
 * and opens a new linked group loan carrying over the old loan's outstanding
 * PRINCIPAL onto new terms, re-splitting it across the loan group's currently
 * active members (membership can drift between the old disbursement and this
 * restructure, so the >=2-active-member guard is re-checked here too, same as
 * DisburseGroupLoanAction).
 */
class RestructureGroupLoanAction
{
    public function __construct(
        private LedgerService $ledger,
        private ChartOfAccounts $chart,
        private ScheduleGenerator $schedule,
    ) {}

    public function execute(GroupLoan $groupLoan, User $restructuredBy, LoanProduct $newProduct, string $reason): GroupLoan
    {
        if ($groupLoan->status !== GroupLoanStatus::Disbursed) {
            throw ValidationException::withMessages(['status' => 'Only disbursed group loans can be restructured.']);
        }
        if ($newProduct->company_id !== $groupLoan->company_id) {
            throw ValidationException::withMessages(['loan_product_id' => 'This product is not available for this loan group.']);
        }
        if (! $newProduct->is_active) {
            throw ValidationException::withMessages(['loan_product_id' => 'This loan product is no longer offered.']);
        }

        $principalOutstanding = $groupLoan->receivableAccount->refresh()->balance;
        if ($principalOutstanding <= 0) {
            throw ValidationException::withMessages(['status' => 'Nothing to restructure — outstanding principal is already zero.']);
        }

        $members = $groupLoan->loanGroup->members()->where('status', 'active')->orderBy('joined_at')->orderBy('id')->get();
        if ($members->count() < 2) {
            throw ValidationException::withMessages(['loan_group' => 'A group loan needs at least 2 active members to restructure.']);
        }

        return DB::transaction(function () use ($groupLoan, $restructuredBy, $newProduct, $reason, $principalOutstanding, $members): GroupLoan {
            $restructuredAt = now();
            $refinanceAmount = $groupLoan->outstanding_balance;

            $newGroupLoan = new GroupLoan([
                'company_id' => $groupLoan->company_id,
                'branch_id' => $groupLoan->branch_id,
                'loan_group_id' => $groupLoan->loan_group_id,
                'loan_product_id' => $newProduct->id,
                'agent_id' => $groupLoan->agent_id,
                'loan_number' => $this->nextLoanNumber($groupLoan->branch),
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
                'status' => GroupLoanStatus::Applied,
                'previous_group_loan_id' => $groupLoan->id,
                'rolled_over_amount' => $principalOutstanding,
                'applied_at' => $restructuredAt,
            ]);
            $newGroupLoan->save();

            $schedule = $this->schedule->generate(
                $newGroupLoan->principal_amount,
                $newGroupLoan->interest_rate_bps,
                $newGroupLoan->term_period_count,
                $newGroupLoan->interest_method,
                $newGroupLoan->repayment_frequency,
                $restructuredAt,
            );

            $totalInterest = array_sum(array_map(
                fn ($installment): int => $installment->interestDue,
                $schedule,
            ));
            $totalRepayable = $newGroupLoan->principal_amount + $totalInterest;
            $newReceivable = $this->chart->groupLoanReceivable($newGroupLoan);

            $this->ledger->post(new EntryData(
                company: $groupLoan->company,
                type: TransactionType::GroupLoanRestructure,
                lines: [
                    ['account' => $newReceivable, 'debit' => $newGroupLoan->principal_amount],
                    ['account' => $groupLoan->receivableAccount, 'credit' => $principalOutstanding],
                ],
                branch: $groupLoan->branch,
                paymentMethod: PaymentMethod::Cash,
                origin: ClientOrigin::Web,
                recordedBy: $restructuredBy,
                recordedAt: $restructuredAt,
                description: "Group loan restructure {$groupLoan->loan_number} -> {$newGroupLoan->loan_number}",
                meta: [
                    'loan_group_id' => $groupLoan->loan_group_id,
                    'previous_group_loan_id' => $groupLoan->id,
                    'new_group_loan_id' => $newGroupLoan->id,
                    'amount' => $principalOutstanding,
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
                'approved_at' => $restructuredAt,
                'disbursed_at' => $restructuredAt,
                'approved_by' => $restructuredBy->id,
            ])->save();

            $groupLoan->forceFill([
                'status' => GroupLoanStatus::Refinanced,
                'outstanding_balance' => 0,
                'refinanced_at' => $restructuredAt,
                'refinance_type' => 'restructure',
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
