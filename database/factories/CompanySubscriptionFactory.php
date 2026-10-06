<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySubscription>
 */
class CompanySubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->startOfDay(),
            'current_period_end' => now()->startOfDay()->addMonthNoOverflow(),
        ];
    }
}
