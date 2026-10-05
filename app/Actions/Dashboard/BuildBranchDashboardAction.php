<?php

namespace App\Actions\Dashboard;

use App\Actions\Reports\BuildPortfolioAtRiskAction;
use App\Enums\AccountStatus;
use App\Enums\EntryStatus;
use App\Enums\LedgerAccountType;
use App\Enums\TransactionType;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Branch-level dashboard numbers, shared by the Filament stat/chart widgets
 * and the /api/v1/dashboard/branch endpoint so every platform reads the same
 * figures. All amounts are integer minor units.
 */
class BuildBranchDashboardAction
{
    private const TREND_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function execute(Branch $branch): array
    {
        return [
            'stats' => $this->stats($branch),
            'collections_trend' => $this->collectionsTrend($branch),
            'loan_status_breakdown' => $this->loanStatusBreakdown($branch),
            'savings_vs_withdrawals' => $this->savingsVsWithdrawals($branch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Branch $branch): array
    {
        $today = AgentDailySummary::query()
            ->where('branch_id', $branch->id)
            ->where('summary_date', now()->toDateString())
            ->get();

        $agentIds = User::query()->where('branch_id', $branch->id)->pluck('id');
        $cashInField = LedgerAccount::query()
            ->where('branch_id', $branch->id)
            ->where('type', LedgerAccountType::Asset)
            ->where('accountable_type', User::class)
            ->whereIn('accountable_id', $agentIds)
            ->sum('balance');

        $par = app(BuildPortfolioAtRiskAction::class)->execute($branch);

        return [
            'collections_today' => (int) $today->sum('collections_total'),
            'collections_count_today' => (int) $today->sum('collections_count'),
            'active_accounts' => SavingsAccount::query()
                ->where('branch_id', $branch->id)
                ->where('status', AccountStatus::Active)
                ->count(),
            'cash_in_field' => (int) $cashInField,
            'outstanding' => $par['outstanding'],
            'at_risk' => $par['at_risk'],
            'par_percent' => $par['par_percent'],
        ];
    }

    /**
     * @return array<int, array{date: string, total: int, count: int}>
     */
    private function collectionsTrend(Branch $branch): array
    {
        $start = CarbonImmutable::today()->subDays(self::TREND_DAYS - 1);

        $byDate = AgentDailySummary::query()
            ->where('branch_id', $branch->id)
            ->where('summary_date', '>=', $start->toDateString())
            ->selectRaw('summary_date, coalesce(sum(collections_total),0) as total, coalesce(sum(collections_count),0) as count')
            ->groupBy('summary_date')
            ->get()
            ->keyBy(fn ($row): string => CarbonImmutable::parse($row->summary_date)->toDateString());

        return collect(range(0, self::TREND_DAYS - 1))
            ->map(function (int $offset) use ($start, $byDate): array {
                $date = $start->addDays($offset)->toDateString();

                return [
                    'date' => $date,
                    'total' => (int) ($byDate[$date]->total ?? 0),
                    'count' => (int) ($byDate[$date]->count ?? 0),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{status: string, count: int, outstanding: int}>
     */
    private function loanStatusBreakdown(Branch $branch): array
    {
        return Loan::query()
            ->where('branch_id', $branch->id)
            ->selectRaw('status, count(*) as count, coalesce(sum(outstanding_balance),0) as outstanding')
            ->groupBy('status')
            ->get()
            ->map(fn ($row): array => [
                'status' => $row->status->value,
                'count' => (int) $row->count,
                'outstanding' => (int) $row->outstanding,
            ])
            ->all();
    }

    /**
     * Deposits from day sheets vs money actually paid out on withdrawals
     * (withdrawal-type journal entries), per day.
     *
     * @return array<int, array{date: string, deposits: int, withdrawals: int}>
     */
    private function savingsVsWithdrawals(Branch $branch): array
    {
        $start = CarbonImmutable::today()->subDays(self::TREND_DAYS - 1);

        $withdrawalsByDate = JournalEntry::query()
            ->where('branch_id', $branch->id)
            ->where('type', TransactionType::Withdrawal)
            ->whereIn('status', [EntryStatus::Completed, EntryStatus::Reversed])
            ->where('recorded_at', '>=', $start->startOfDay())
            ->withSum('lines as amount_sum', 'debit')
            ->get()
            ->groupBy(fn (JournalEntry $entry): string => $entry->recorded_at->toDateString())
            ->map(fn ($group): int => (int) $group->sum('amount_sum'));

        $deposits = collect($this->collectionsTrend($branch))->keyBy('date');

        return collect(range(0, self::TREND_DAYS - 1))
            ->map(function (int $offset) use ($start, $deposits, $withdrawalsByDate): array {
                $date = $start->addDays($offset)->toDateString();

                return [
                    'date' => $date,
                    'deposits' => (int) ($deposits[$date]['total'] ?? 0),
                    'withdrawals' => (int) ($withdrawalsByDate[$date] ?? 0),
                ];
            })
            ->all();
    }
}
