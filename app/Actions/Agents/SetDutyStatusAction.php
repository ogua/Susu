<?php

namespace App\Actions\Agents;

use App\Models\AgentDailySummary;
use App\Models\AgentLivePosition;
use App\Models\User;

/**
 * Duty toggle: going on duty opens today's day sheet and enables tracking;
 * going off duty stops tracking (day close/submit is a separate step).
 */
class SetDutyStatusAction
{
    public function execute(User $agent, bool $onDuty): AgentLivePosition
    {
        if ($onDuty && $agent->branch_id !== null) {
            AgentDailySummary::firstOrCreate(
                ['agent_id' => $agent->id, 'summary_date' => now()->toDateString()],
                ['company_id' => $agent->company_id, 'branch_id' => $agent->branch_id],
            );
        }

        return AgentLivePosition::updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'company_id' => $agent->company_id,
                'branch_id' => $agent->branch_id,
                'on_duty' => $onDuty,
            ],
        );
    }
}
