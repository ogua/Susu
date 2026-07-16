<?php

namespace App\Actions\Dashboard;

use App\Enums\AccountStatus;
use App\Models\AgentDailySummary;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Carbon\CarbonImmutable;

/**
 * A field agent's own dashboard: today's day sheet, assigned accounts, and a
 * 30-day personal collections trend. Powers /api/v1/dashboard/agent (the
 * mobile agent home screen). All amounts are integer minor units.
 */
class BuildAgentDashboardAction
{
    private const TREND_DAYS = 30;

    public function __construct(private ChartOfAccounts $chart) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $agent): array
    {
        $today = AgentDailySummary::query()
            ->where('agent_id', $agent->id)
            ->where('summary_date', now()->toDateString())
            ->first();

        $start = CarbonImmutable::today()->subDays(self::TREND_DAYS - 1);

        $byDate = AgentDailySummary::query()
            ->where('agent_id', $agent->id)
            ->where('summary_date', '>=', $start->toDateString())
            ->get()
            ->keyBy(fn (AgentDailySummary $summary): string => CarbonImmutable::parse($summary->summary_date)->toDateString());

        $trend = collect(range(0, self::TREND_DAYS - 1))
            ->map(function (int $offset) use ($start, $byDate): array {
                $date = $start->addDays($offset)->toDateString();

                return [
                    'date' => $date,
                    'total' => (int) ($byDate[$date]->collections_total ?? 0),
                    'count' => (int) ($byDate[$date]->collections_count ?? 0),
                ];
            })
            ->all();

        return [
            'today' => [
                'collections_total' => (int) ($today->collections_total ?? 0),
                'collections_count' => (int) ($today->collections_count ?? 0),
                'expected_cash' => (int) $this->chart->agentCash($agent)->refresh()->balance,
                'variance' => (int) ($today->variance ?? 0),
                'summary_status' => $today?->status?->value,
            ],
            'accounts' => [
                'active_count' => SavingsAccount::query()
                    ->where('agent_id', $agent->id)
                    ->where('status', AccountStatus::Active)
                    ->count(),
            ],
            'trend' => $trend,
        ];
    }
}
