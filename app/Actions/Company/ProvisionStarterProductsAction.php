<?php

namespace App\Actions\Company;

use App\Enums\CommissionType;
use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use App\Enums\SavingsProductType;
use App\Models\Company;
use App\Models\LoanProduct;
use App\Models\SavingsProduct;
use Illuminate\Support\Facades\DB;

/**
 * Gives a new company a working product catalogue so its staff can open
 * accounts and issue loans on day one. Matched by code and never
 * overwritten: re-running only adds products the company doesn't have, so a
 * company's own edits to a starter product survive.
 */
class ProvisionStarterProductsAction
{
    /** @var list<array<string, mixed>> */
    public const SAVINGS_PRODUCTS = [
        ['code' => 'DS-005', 'name' => 'Daily Susu GHS 5', 'type' => SavingsProductType::DailySusu, 'contribution_amount' => 5_00, 'cycle_length_days' => 31, 'commission_type' => CommissionType::FirstContributionPerCycle, 'commission_value' => 0],
        ['code' => 'DS-010', 'name' => 'Daily Susu GHS 10', 'type' => SavingsProductType::DailySusu, 'contribution_amount' => 10_00, 'cycle_length_days' => 31, 'commission_type' => CommissionType::FirstContributionPerCycle, 'commission_value' => 0],
        ['code' => 'TS-GOAL', 'name' => 'Target Savings', 'type' => SavingsProductType::Target, 'contribution_amount' => 10_00, 'cycle_length_days' => 31, 'commission_type' => CommissionType::None, 'commission_value' => 0, 'early_withdrawal_penalty_bps' => 500],
        ['code' => 'FD-091', 'name' => 'Fixed Deposit 91 Days', 'type' => SavingsProductType::FixedDeposit, 'contribution_amount' => 500_00, 'cycle_length_days' => 1, 'commission_type' => CommissionType::None, 'commission_value' => 0, 'interest_rate_bps' => 1200, 'term_days' => 91],
        ['code' => 'SH-COOP', 'name' => 'Share Capital', 'type' => SavingsProductType::Shares, 'contribution_amount' => 10_00, 'cycle_length_days' => 1, 'commission_type' => CommissionType::None, 'commission_value' => 0, 'par_value' => 10_00],
    ];

    /** @var list<array<string, mixed>> */
    public const LOAN_PRODUCTS = [
        ['code' => 'LN-BOOST', 'name' => 'Susu Boost Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 300, 'term_period_count' => 6, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 10_00, 'penalty_rate_bps' => 500, 'grace_period_days' => 3, 'min_amount' => 100_00, 'max_amount' => 5_000_00],
        ['code' => 'LN-TRADER', 'name' => 'Daily Trader Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 20, 'term_period_count' => 60, 'repayment_frequency' => LoanFrequency::Daily, 'origination_fee_amount' => 5_00, 'penalty_rate_bps' => 200, 'grace_period_days' => 1, 'min_amount' => 50_00, 'max_amount' => 2_000_00],
        ['code' => 'LN-WEEKLY', 'name' => 'Weekly Business Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 100, 'term_period_count' => 16, 'repayment_frequency' => LoanFrequency::Weekly, 'origination_fee_amount' => 10_00, 'penalty_rate_bps' => 300, 'grace_period_days' => 2, 'min_amount' => 200_00, 'max_amount' => 3_000_00],
        ['code' => 'LN-GROUP', 'name' => 'Group Solidarity Loan', 'interest_method' => InterestMethod::Flat, 'interest_rate_bps' => 250, 'term_period_count' => 24, 'repayment_frequency' => LoanFrequency::Weekly, 'origination_fee_amount' => 0, 'penalty_rate_bps' => 300, 'grace_period_days' => 3, 'min_amount' => 300_00, 'max_amount' => 4_000_00],
        ['code' => 'LN-SME', 'name' => 'SME Working Capital Loan', 'interest_method' => InterestMethod::ReducingBalance, 'interest_rate_bps' => 400, 'term_period_count' => 12, 'repayment_frequency' => LoanFrequency::Monthly, 'origination_fee_amount' => 50_00, 'penalty_rate_bps' => 500, 'grace_period_days' => 5, 'min_amount' => 1_000_00, 'max_amount' => 20_000_00],
    ];

    /**
     * @return array{savings: int, loans: int} How many products were added.
     */
    public function execute(Company $company): array
    {
        return DB::transaction(function () use ($company): array {
            $savings = 0;
            foreach (self::SAVINGS_PRODUCTS as $attributes) {
                $product = SavingsProduct::withTrashed()->firstOrCreate(
                    ['company_id' => $company->id, 'code' => $attributes['code']],
                    [...$attributes, 'is_active' => true],
                );
                $savings += (int) $product->wasRecentlyCreated;
            }

            $loans = 0;
            foreach (self::LOAN_PRODUCTS as $attributes) {
                $product = LoanProduct::withTrashed()->firstOrCreate(
                    ['company_id' => $company->id, 'code' => $attributes['code']],
                    [...$attributes, 'is_active' => true],
                );
                $loans += (int) $product->wasRecentlyCreated;
            }

            return ['savings' => $savings, 'loans' => $loans];
        });
    }
}
