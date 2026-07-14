<?php

namespace App\Actions\Reports;

use App\Enums\InstallmentStatus;
use App\Models\Branch;
use App\Models\LoanInstallment;
use Illuminate\Database\Eloquent\Collection;

class BuildDefaultersReportAction
{
    /**
     * Every overdue loan installment for the branch, oldest-first — flagged
     * (and penalized) by loans:flag-arrears, not computed here, so this
     * stays in sync with whatever already accrued against the loan.
     *
     * @return Collection<int, LoanInstallment>
     */
    public function execute(Branch $branch): Collection
    {
        return LoanInstallment::query()
            ->where('status', InstallmentStatus::Overdue)
            ->whereHas('loan', fn ($query) => $query->where('branch_id', $branch->id))
            ->with(['loan.customer', 'loan.agent'])
            ->orderBy('due_date')
            ->get();
    }
}
