<?php

namespace App\Actions\Company;

use App\Models\Company;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Suspends or reactivates a tenant. A suspended company's staff and
 * customers are refused by the admin panel (User::canAccessPanel), by API
 * login, and by EnsureAccountIsActive on every authenticated API call; their
 * API tokens are also revoked so the mobile and desktop apps sign out.
 */
class SetCompanyActiveStatusAction
{
    /**
     * @param  ?string  $reason  Why it was suspended (Company::SUSPENDED_*); cleared on reactivation.
     */
    public function execute(Company $company, bool $isActive, ?string $reason = Company::SUSPENDED_BY_OPERATOR): Company
    {
        $company->update([
            'is_active' => $isActive,
            'suspended_reason' => $isActive ? null : $reason,
        ]);

        if (! $isActive) {
            PersonalAccessToken::query()
                ->where('tokenable_type', $company->users()->getRelated()->getMorphClass())
                ->whereIn('tokenable_id', $company->users()->select('id'))
                ->delete();
        }

        return $company;
    }
}
