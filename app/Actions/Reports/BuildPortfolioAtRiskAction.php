<?php

namespace App\Actions\Reports;

use App\Enums\GroupLoanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GroupLoan;
use App\Models\Loan;

/**
 * Portfolio-at-risk over a branch's running book — individual loans AND
 * group loans. A loan with any overdue installment counts its entire
 * outstanding balance at risk. Shared by the loan portfolio report and the
 * branch dashboard so both always show the same figure.
 */
class BuildPortfolioAtRiskAction
{
    /**
     * @return array{outstanding: int, at_risk: int, par_percent: float}
     */
    public function execute(Branch $branch): array
    {
        return $this->compute('branch_id', $branch->id);
    }

    /**
     * The same figure across every branch of a company (platform reports).
     *
     * @return array{outstanding: int, at_risk: int, par_percent: float}
     */
    public function forCompany(Company $company): array
    {
        return $this->compute('company_id', $company->id);
    }

    /**
     * @return array{outstanding: int, at_risk: int, par_percent: float}
     */
    private function compute(string $scopeColumn, string $scopeId): array
    {
        $loans = Loan::query()
            ->where($scopeColumn, $scopeId)
            ->where('status', LoanStatus::Disbursed);

        $groupLoans = GroupLoan::query()
            ->where($scopeColumn, $scopeId)
            ->where('status', GroupLoanStatus::Active);

        $overdue = fn ($query) => $query->where('status', InstallmentStatus::Overdue);

        $outstanding = (int) (clone $loans)->sum('outstanding_balance')
            + (int) (clone $groupLoans)->sum('outstanding_balance');

        $atRisk = (int) (clone $loans)->whereHas('installments', $overdue)->sum('outstanding_balance')
            + (int) (clone $groupLoans)->whereHas('installments', $overdue)->sum('outstanding_balance');

        return [
            'outstanding' => $outstanding,
            'at_risk' => $atRisk,
            'par_percent' => $outstanding > 0 ? round(($atRisk / $outstanding) * 100, 1) : 0.0,
        ];
    }
}
