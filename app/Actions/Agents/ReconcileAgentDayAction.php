<?php

namespace App\Actions\Agents;

use App\Enums\AgentSummaryStatus;
use App\Models\AgentDailySummary;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Branch manager signs off (or flags) a submitted day sheet. */
class ReconcileAgentDayAction
{
    public function execute(
        User $reconciledBy,
        AgentDailySummary $summary,
        bool $flag = false,
        ?string $notes = null,
    ): AgentDailySummary {
        if ($summary->status !== AgentSummaryStatus::Submitted) {
            throw ValidationException::withMessages(['summary' => 'Only submitted day sheets can be reconciled.']);
        }

        $summary->update([
            'status' => $flag ? AgentSummaryStatus::Flagged : AgentSummaryStatus::Reconciled,
            'reconciled_by' => $reconciledBy->id,
            'notes' => $notes ?? $summary->notes,
        ]);

        return $summary;
    }
}
