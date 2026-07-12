<?php

namespace App\Actions\Agents;

use App\Enums\AgentSummaryStatus;
use App\Models\AgentDailySummary;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Day close: the agent declares the cash they hold; expected cash is simply
 * the agent's cash-in-hand ledger balance (the double-entry payoff).
 * Idempotent on client_reference for offline day-close.
 */
class SubmitAgentDailySummaryAction
{
    public function __construct(private ChartOfAccounts $chart) {}

    public function execute(
        User $agent,
        int $declaredCash,
        ?CarbonInterface $date = null,
        ?string $notes = null,
        ?string $clientReference = null,
    ): AgentDailySummary {
        if ($clientReference !== null) {
            $existing = AgentDailySummary::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $date ??= now();

        $summary = AgentDailySummary::firstOrCreate(
            ['agent_id' => $agent->id, 'summary_date' => $date->toDateString()],
            ['company_id' => $agent->company_id, 'branch_id' => $agent->branch_id],
        );

        if (in_array($summary->status, [AgentSummaryStatus::Reconciled], true)) {
            throw ValidationException::withMessages(['summary' => 'This day has already been reconciled.']);
        }

        $expected = $this->chart->agentCash($agent)->refresh()->balance;

        $summary->update([
            'expected_cash' => $expected,
            'declared_cash' => $declaredCash,
            'variance' => $declaredCash - $expected,
            'status' => AgentSummaryStatus::Submitted,
            'notes' => $notes,
            'client_reference' => $clientReference,
        ]);

        return $summary;
    }
}
