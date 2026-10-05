<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyPaymentSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPaymentSetting>
 */
class CompanyPaymentSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'paystack_public_key' => 'pk_test_'.fake()->regexify('[a-z0-9]{32}'),
            'paystack_secret_key' => 'sk_test_'.fake()->regexify('[a-z0-9]{32}'),
        ];
    }
}
