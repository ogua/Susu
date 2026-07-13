<?php

namespace App\Policies;

use App\Models\PaymentIntent;
use App\Models\User;

/** Payment intents are system-managed by the charge/verify/webhook flow — view and re-verify only. */
class PaymentIntentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function view(User $user, PaymentIntent $intent): bool
    {
        return $user->company_id === $intent->company_id
            && $user->hasRole(['company_admin', 'branch_manager']);
    }

    public function verify(User $user, PaymentIntent $intent): bool
    {
        return $user->company_id === $intent->company_id
            && $user->hasRole(['company_admin', 'branch_manager']);
    }
}
