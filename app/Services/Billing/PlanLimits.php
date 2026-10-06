<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/**
 * Caps on branches, staff and customers from a company's plan. Enforced in
 * the shared create actions, so the web, the API and offline sync all hit
 * the same check. A company with no (or a cancelled) subscription has no
 * caps — companies onboarded before billing existed keep working.
 */
class PlanLimits
{
    public function planFor(Company $company): ?Plan
    {
        $subscription = $company->subscription()->with('plan')->first();

        if ($subscription === null || $subscription->status === SubscriptionStatus::Cancelled) {
            return null;
        }

        return $subscription->plan;
    }

    public function usage(Company $company, string $resource): int
    {
        return match ($resource) {
            'branches' => $company->branches()->count(),
            'staff' => $company->users()->whereDoesntHave('roles', fn ($roles) => $roles->where('name', 'customer'))->count(),
            'customers' => $company->customers()->count(),
        };
    }

    /**
     * @return array<string, array{used: int, limit: ?int}>
     */
    public function summary(Company $company): array
    {
        $plan = $this->planFor($company);

        return collect(array_keys(Plan::LIMITS))
            ->mapWithKeys(fn (string $resource): array => [$resource => [
                'used' => $this->usage($company, $resource),
                'limit' => $plan?->limitFor($resource),
            ]])
            ->all();
    }

    /**
     * @throws ValidationException when adding $count more would exceed the plan.
     */
    public function assertCanAdd(Company $company, string $resource, int $count = 1): void
    {
        $limit = $this->planFor($company)?->limitFor($resource);

        if ($limit === null || $this->usage($company, $resource) + $count <= $limit) {
            return;
        }

        throw ValidationException::withMessages([
            $resource => "Your plan allows {$limit} {$resource}. Upgrade the subscription to add more.",
        ]);
    }
}
