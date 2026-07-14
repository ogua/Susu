<?php

namespace App\Filament\Widgets;

use App\Enums\AccountStatus;
use App\Enums\InstallmentStatus;
use App\Enums\LedgerAccountType;
use App\Enums\LoanStatus;
use App\Models\AgentDailySummary;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardOverview extends StatsOverviewWidget
{
    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $branchId = Filament::getTenant()?->id;

        return [
            $this->collectionsToday($branchId),
            $this->activeAccounts($branchId),
            $this->cashInField($branchId),
            $this->portfolioAtRisk($branchId),
        ];
    }

    private function collectionsToday(?string $branchId): Stat
    {
        $summaries = AgentDailySummary::query()
            ->where('branch_id', $branchId)
            ->where('summary_date', now()->toDateString())
            ->get();

        return Stat::make('Collections Today', Money::format((int) $summaries->sum('collections_total')))
            ->description($summaries->sum('collections_count').' collection(s) recorded')
            ->icon('heroicon-o-banknotes')
            ->color('success');
    }

    private function activeAccounts(?string $branchId): Stat
    {
        $count = SavingsAccount::query()
            ->where('branch_id', $branchId)
            ->where('status', AccountStatus::Active)
            ->count();

        return Stat::make('Active Accounts', (string) $count)
            ->icon('heroicon-o-users')
            ->color('info');
    }

    /** Sum of every agent's cash-in-hand ledger account for this branch — the day's field float. */
    private function cashInField(?string $branchId): Stat
    {
        $agentIds = User::query()->where('branch_id', $branchId)->pluck('id');

        $total = LedgerAccount::query()
            ->where('branch_id', $branchId)
            ->where('type', LedgerAccountType::Asset)
            ->where('accountable_type', User::class)
            ->whereIn('accountable_id', $agentIds)
            ->sum('balance');

        return Stat::make('Cash In Field', Money::format((int) $total))
            ->description('Total agent cash-in-hand')
            ->icon('heroicon-o-wallet')
            ->color('warning');
    }

    /** Standard microfinance PAR: a loan with any overdue installment counts its whole outstanding balance as at-risk. */
    private function portfolioAtRisk(?string $branchId): Stat
    {
        $disbursed = Loan::query()
            ->where('branch_id', $branchId)
            ->where('status', LoanStatus::Disbursed);

        $totalOutstanding = (clone $disbursed)->sum('outstanding_balance');
        $atRiskOutstanding = (clone $disbursed)
            ->whereHas('installments', fn ($query) => $query->where('status', InstallmentStatus::Overdue))
            ->sum('outstanding_balance');

        $parPercent = $totalOutstanding > 0 ? round(($atRiskOutstanding / $totalOutstanding) * 100, 1) : 0.0;

        return Stat::make('Portfolio At Risk', $parPercent.'%')
            ->description(Money::format((int) $atRiskOutstanding).' of '.Money::format((int) $totalOutstanding).' outstanding')
            ->icon('heroicon-o-exclamation-triangle')
            ->color($parPercent > 10 ? 'danger' : 'success');
    }
}
