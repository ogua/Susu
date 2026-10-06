<?php

namespace App\Actions\Reports;

use App\Enums\EntryStatus;
use App\Enums\TransactionType;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Loan risk and field-force activity side by side for every company:
 * portfolio at risk (same definition as each branch's own report, via
 * BuildPortfolioAtRiskAction) and how many field agents actually collected
 * in the window. Lets the operator spot a tenant whose book is going bad or
 * whose agents have stopped working before it becomes a support crisis.
 *
 * @phpstan-type CompanyRiskRow array{company_id: string, company: string, is_active: bool, outstanding: int, at_risk: int, par_percent: float, agents: int, active_agents: int, collections: int, collections_amount: int, per_active_agent: int}
 */
class BuildCompanyRiskReportAction
{
    public function __construct(private readonly BuildPortfolioAtRiskAction $portfolioAtRisk) {}

    /**
     * @return Collection<string, CompanyRiskRow> keyed by company id
     */
    public function execute(int $windowDays = 30): Collection
    {
        $since = now()->subDays($windowDays);

        $agentCounts = User::role('field_agent')
            ->where('is_active', true)
            ->whereNotNull('company_id')
            ->selectRaw('company_id, count(*) as aggregate')
            ->groupBy('company_id')
            ->pluck('aggregate', 'company_id');

        $collections = JournalEntry::query()
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.type', TransactionType::Collection)
            ->where('journal_entries.status', EntryStatus::Completed)
            ->where('journal_entries.recorded_at', '>=', $since)
            ->groupBy('journal_entries.company_id')
            ->selectRaw('journal_entries.company_id, count(distinct journal_entries.id) as entries, count(distinct journal_entries.recorded_by) as active_agents, coalesce(sum(journal_lines.debit), 0) as amount')
            ->get()
            ->keyBy('company_id');

        return Company::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (Company $company) use ($agentCounts, $collections): array {
                $par = $this->portfolioAtRisk->forCompany($company);
                $activity = $collections->get($company->id);
                $activeAgents = (int) ($activity->active_agents ?? 0);
                $amount = (int) ($activity->amount ?? 0);

                return [$company->id => [
                    'company_id' => $company->id,
                    'company' => $company->name,
                    'is_active' => $company->is_active,
                    'outstanding' => $par['outstanding'],
                    'at_risk' => $par['at_risk'],
                    'par_percent' => $par['par_percent'],
                    'agents' => (int) ($agentCounts[$company->id] ?? 0),
                    'active_agents' => $activeAgents,
                    'collections' => (int) ($activity->entries ?? 0),
                    'collections_amount' => $amount,
                    'per_active_agent' => $activeAgents > 0 ? intdiv($amount, $activeAgents) : 0,
                ]];
            });
    }
}
