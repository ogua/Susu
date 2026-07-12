<?php

namespace App\Policies;

use App\Models\AgentLivePosition;
use App\Models\User;

/** Tracking data is staff-only and duty-scoped (Act 843) — never customer-visible. */
class AgentLivePositionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function view(User $user, AgentLivePosition $position): bool
    {
        return $user->company_id === $position->company_id
            && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
