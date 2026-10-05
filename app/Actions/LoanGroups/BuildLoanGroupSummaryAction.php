<?php

namespace App\Actions\LoanGroups;

use App\Enums\GroupLoanStatus;
use App\Models\GroupLoanDeposit;
use App\Models\GroupLoanInstallment;
use App\Models\GroupLoanRepayment;
use App\Models\LoanGroup;
use Carbon\CarbonInterface;

/**
 * The group's headline figures — the same numbers on the web overview and in
 * GET /api/v1/loan-groups/{id} (summary). All amounts in pesewas.
 */
class BuildLoanGroupSummaryAction
{
    /**
     * @return array{active_members: int, active_loans: int, draft_loans: int, total_disbursed: int, total_paid: int, outstanding: int, overdue: int, deposits_collected: int}
     */
    public function execute(LoanGroup $loanGroup, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();
        $loans = $loanGroup->groupLoans();

        return [
            'active_members' => $loanGroup->members()->where('status', 'active')->count(),
            'active_loans' => (clone $loans)->where('status', GroupLoanStatus::Active)->count(),
            'draft_loans' => (clone $loans)->where('status', GroupLoanStatus::Draft)->count(),
            'total_disbursed' => (int) (clone $loans)->whereNotNull('activated_at')->sum('principal_amount'),
            'total_paid' => (int) GroupLoanRepayment::whereIn('group_loan_id', (clone $loans)->select('id'))->sum('amount'),
            'outstanding' => (int) (clone $loans)->where('status', GroupLoanStatus::Active)->sum('outstanding_balance'),
            'overdue' => (int) GroupLoanInstallment::query()
                ->whereIn('group_loan_id', (clone $loans)->where('status', GroupLoanStatus::Active)->select('id'))
                ->whereDate('due_date', '<', $asOf->toDateString())
                ->selectRaw('COALESCE(SUM(amount_due - amount_paid), 0) as overdue')
                ->value('overdue'),
            'deposits_collected' => (int) GroupLoanDeposit::whereIn('group_loan_id', (clone $loans)->select('id'))->sum('amount'),
        ];
    }
}
