<?php

namespace Database\Factories;

use App\Enums\MobileMoneyProvider;
use App\Enums\PaymentFlow;
use App\Enums\PaymentIntentStatus;
use App\Models\Branch;
use App\Models\PaymentIntent;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentIntent>
 */
class PaymentIntentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'payable_id' => fn (array $attributes) => SavingsAccount::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'company_id' => Branch::find($attributes['branch_id'])->company_id,
            ])->id,
            'payable_type' => SavingsAccount::class,
            'initiated_by' => fn (array $attributes) => User::factory()->fieldAgent(
                Branch::find($attributes['branch_id'])
            )->create()->id,
            'flow' => PaymentFlow::ChargeApi,
            'channel' => MobileMoneyProvider::Mtn,
            'phone' => '0244000000',
            'amount' => 500,
            'status' => PaymentIntentStatus::Initiated,
            'client_reference' => (string) Str::uuid(),
        ];
    }
}
