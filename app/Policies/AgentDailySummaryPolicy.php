<?php

namespace App\Policies;

use App\Models\AgentDailySummary;
use App\Models\User;

class AgentDailySummaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, AgentDailySummary $summary): bool
    {
        if ($user->company_id !== $summary->company_id) {
            return false;
        }

        return $user->hasRole(['company_admin', 'branch_manager'])
            || ($user->hasRole('field_agent') && $summary->agent_id === $user->id);
    }

    public function reconcile(User $user, AgentDailySummary $summary): bool
    {
        return $user->company_id === $summary->company_id
            && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
