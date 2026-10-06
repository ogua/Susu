<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\CompanySubscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionInvoice>
 */
class SubscriptionInvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_subscription_id' => CompanySubscription::factory(),
            'company_id' => fn (array $attributes) => CompanySubscription::find($attributes['company_subscription_id'])->company_id,
            'plan_id' => fn (array $attributes) => CompanySubscription::find($attributes['company_subscription_id'])->plan_id,
            'number' => 'INV-'.fake()->unique()->numerify('######'),
            'amount' => 200_00,
            'currency' => 'GHS',
            'period_start' => now()->startOfDay(),
            'period_end' => now()->startOfDay()->addMonthNoOverflow(),
            'due_at' => now()->addDays(7),
            'status' => InvoiceStatus::Unpaid,
        ];
    }
}
