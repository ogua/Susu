<?php

namespace Database\Factories;

use App\Enums\AgentSummaryStatus;
use App\Models\AgentDailySummary;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentDailySummary>
 */
class AgentDailySummaryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'agent_id' => fn (array $attributes) => User::factory()->fieldAgent(
                Branch::find($attributes['branch_id'])
            )->create()->id,
            'summary_date' => now()->toDateString(),
            'status' => AgentSummaryStatus::Open,
        ];
    }
}
