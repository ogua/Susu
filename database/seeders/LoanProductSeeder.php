<?php

namespace Database\Seeders;

use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use App\Models\Company;
use App\Models\LoanProduct;
use Illuminate\Database\Seeder;

/**
 * Seeds the first company's loan product catalogue, covering both interest
 * methods and every repayment frequency. Re-runnable: matched by code.
 */
class LoanProductSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    private const PRODUCTS = [
        ['code' => 'LN-BOOST', 'name' => 'Susu Boost Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 300, 'term_period_count' => 6, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 10_00, 'penalty_rate_bps' => 500, 'grace_period_days' => 3, 'min_amount' => 100_00, 'max_amount' => 5_000_00],
        ['code' => 'LN-TRADER', 'name' => 'Daily Trader Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 20, 'term_period_count' => 60, 'repayment_frequency' => LoanFrequency::Daily, 'origination_fee_amount' => 5_00, 'penalty_rate_bps' => 200, 'grace_period_days' => 1, 'min_amount' => 50_00, 'max_amount' => 2_000_00],
        ['code' => 'LN-MKTWMN', 'name' => 'Market Women Weekly Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 100, 'term_period_count' => 16, 'repayment_frequency' => LoanFrequency::Weekly, 'origination_fee_amount' => 10_00, 'penalty_rate_bps' => 300, 'grace_period_days' => 2, 'min_amount' => 200_00, 'max_amount' => 3_000_00],
        ['code' => 'LN-GROUP', 'name' => 'Group Solidarity Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 250, 'term_period_count' => 24, 'repayment_frequency' => LoanFrequency::Weekly, 'origination_fee_amount' => 0, 'penalty_rate_bps' => 300, 'grace_period_days' => 3, 'min_amount' => 300_00, 'max_amount' => 4_000_00],
        ['code' => 'LN-SME', 'name' => 'SME Working Capital Loan', 'interest_method' => InterestMethod::ReducingBalance, 'interest_rate_bps' => 400, 'term_period_count' => 12, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 50_00, 'penalty_rate_bps' => 500, 'grace_period_days' => 5, 'min_amount' => 1_000_00, 'max_amount' => 20_000_00],
        ['code' => 'LN-ASSET', 'name' => 'Artisan Equipment Loan', 'interest_method' => InterestMethod::ReducingBalance, 'interest_rate_bps' => 350, 'term_period_count' => 18, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 30_00, 'penalty_rate_bps' => 500, 'grace_period_days' => 5, 'min_amount' => 500_00, 'max_amount' => 15_000_00],
        ['code' => 'LN-AGRIC', 'name' => 'Agric Seasonal Loan', 'interest_method' => InterestMethod::ReducingBalance, 'interest_rate_bps' => 300, 'term_period_count' => 9, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 20_00, 'penalty_rate_bps' => 400, 'grace_period_days' => 7, 'min_amount' => 500_00, 'max_amount' => 10_000_00],
        ['code' => 'LN-SCHOOL', 'name' => 'School Fees Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 250, 'term_period_count' => 4, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 10_00, 'penalty_rate_bps' => 500, 'grace_period_days' => 3, 'min_amount' => 200_00, 'max_amount' => 3_000_00],
        ['code' => 'LN-EMERG', 'name' => 'Emergency Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 500, 'term_period_count' => 8, 'repayment_frequency' => LoanFrequency::Weekly, 'origination_fee_amount' => 5_00, 'penalty_rate_bps' => 1000, 'grace_period_days' => 0, 'min_amount' => 50_00, 'max_amount' => 1_000_00],
        ['code' => 'LN-SALARY', 'name' => 'Salary Advance Loan', 'interest_method' => InterestMethod::ReducingBalance, 'interest_rate_bps' => 450, 'term_period_count' => 3, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 15_00, 'penalty_rate_bps' => 800, 'grace_period_days' => 2, 'min_amount' => 200_00, 'max_amount' => 5_000_00],
        ['code' => 'LN-LEGACY', 'name' => 'Legacy Micro Loan (Retired)', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 600, 'term_period_count' => 6, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 0, 'penalty_rate_bps' => 500, 'grace_period_days' => 3, 'min_amount' => 50_00, 'max_amount' => 1_500_00, 'is_active' => false],
    ];

    public function run(): void
    {
        $company = Company::query()->oldest()->first();

        if (! $company) {
            $this->command?->warn('LoanProductSeeder skipped: no company found. Run DemoSeeder first.');

            return;
        }

        foreach (self::PRODUCTS as $attributes) {
            LoanProduct::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $attributes['code']],
                ['is_active' => true, ...$attributes],
            );
        }

        $this->command?->info(sprintf('Seeded %d loan products for "%s".', count(self::PRODUCTS), $company->name));
    }
}
