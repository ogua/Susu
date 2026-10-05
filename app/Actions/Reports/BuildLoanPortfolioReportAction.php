<?php

namespace App\Actions\Reports;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The branch's loan book: every loan (optionally filtered by status and
 * application date), a per-status summary, and portfolio-at-risk. Ranged on
 * applied_at because it is the only lifecycle date every loan has.
 *
 * @phpstan-type LoanPortfolioResult array{
 *     loans: \Illuminate\Database\Eloquent\Collection<int, Loan>,
 *     statusSummary: Collection<int, array{status: LoanStatus, count: int, principal: int, outstanding: int}>,
 *     totalPrincipal: int,
 *     totalOutstanding: int,
 *     atRiskOutstanding: int,
 *     parPercent: float,
 *     from: ?CarbonImmutable,
 *     to: ?CarbonImmutable,
 *     status: ?LoanStatus,
 * }
 */
class BuildLoanPortfolioReportAction
{
    /**
     * @return LoanPortfolioResult
     */
    public function execute(
        Branch $branch,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?LoanStatus $status = null,
    ): array {
        $loans = Loan::query()
            ->where('branch_id', $branch->id)
            ->when($from, fn ($query) => $query->where('applied_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('applied_at', '<=', $to->endOfDay()))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->with(['customer', 'agent', 'loanProduct'])
            ->orderBy('applied_at')
            ->get();

        $statusSummary = $loans
            ->groupBy(fn (Loan $loan): string => $loan->status->value)
            ->map(fn (Collection $group): array => [
                'status' => $group->first()->status,
                'count' => $group->count(),
                'principal' => (int) $group->sum('principal_amount'),
                'outstanding' => (int) $group->sum('outstanding_balance'),
            ])
            ->values();

        // PAR over the whole branch book (not the filtered slice), group
        // loans included.
        $par = app(BuildPortfolioAtRiskAction::class)->execute($branch);

        return [
            'loans' => $loans,
            'statusSummary' => $statusSummary,
            'totalPrincipal' => (int) $loans->sum('principal_amount'),
            'totalOutstanding' => (int) $loans->sum('outstanding_balance'),
            'atRiskOutstanding' => $par['at_risk'],
            'parPercent' => $par['par_percent'],
            'from' => $from,
            'to' => $to,
            'status' => $status,
        ];
    }
}
