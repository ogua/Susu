<?php

namespace Database\Seeders;

use App\Actions\Savings\BuySharesAction;
use App\Actions\Savings\OpenSavingsAccountAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\CommissionType;
use App\Enums\PaymentMethod;
use App\Enums\SavingsProductType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the first company's savings product catalogue (every product type and
 * commission model) and opens funded accounts for every customer. Accounts
 * are opened and funded through the real Actions so balances, cycles and the
 * ledger stay consistent. Re-runnable: products are matched by code and a
 * customer never gets a second account on the same product.
 */
class SavingsSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    private const PRODUCTS = [
        ['code' => 'DS-005', 'name' => 'Daily Susu GHS 5', 'type' => SavingsProductType::DailySusu, 'contribution_amount' => 5_00, 'cycle_length_days' => 31, 'commission_type' => CommissionType::FirstContributionPerCycle, 'commission_value' => 0],
        ['code' => 'DS-010', 'name' => 'Daily Susu GHS 10', 'type' => SavingsProductType::DailySusu, 'contribution_amount' => 10_00, 'cycle_length_days' => 31, 'commission_type' => CommissionType::FirstContributionPerCycle, 'commission_value' => 0],
        ['code' => 'DS-020', 'name' => 'Daily Susu GHS 20 (Percentage)', 'type' => SavingsProductType::DailySusu, 'contribution_amount' => 20_00, 'cycle_length_days' => 31, 'commission_type' => CommissionType::Percentage, 'commission_value' => 300],
        ['code' => 'DS-050', 'name' => 'Premium Susu GHS 50 (Flat)', 'type' => SavingsProductType::DailySusu, 'contribution_amount' => 50_00, 'cycle_length_days' => 30, 'commission_type' => CommissionType::FlatPerCycle, 'commission_value' => 30_00],
        ['code' => 'WS-100', 'name' => 'Weekly Susu GHS 100 (Balance %)', 'type' => SavingsProductType::DailySusu, 'contribution_amount' => 100_00, 'cycle_length_days' => 4, 'commission_type' => CommissionType::PercentageOfBalancePerCycle, 'commission_value' => 100],
        ['code' => 'TS-FEES', 'name' => 'School Fees Target Savings', 'type' => SavingsProductType::Target, 'contribution_amount' => 10_00, 'cycle_length_days' => 31, 'commission_type' => CommissionType::None, 'commission_value' => 0, 'early_withdrawal_penalty_bps' => 1000],
        ['code' => 'TS-RENT', 'name' => 'Rent Advance Target Savings', 'type' => SavingsProductType::Target, 'contribution_amount' => 20_00, 'cycle_length_days' => 31, 'commission_type' => CommissionType::None, 'commission_value' => 0, 'early_withdrawal_penalty_bps' => 500],
        ['code' => 'FD-091', 'name' => 'Fixed Deposit 91 Days', 'type' => SavingsProductType::FixedDeposit, 'contribution_amount' => 500_00, 'cycle_length_days' => 1, 'commission_type' => CommissionType::None, 'commission_value' => 0, 'interest_rate_bps' => 1200],
        ['code' => 'FD-182', 'name' => 'Fixed Deposit 182 Days', 'type' => SavingsProductType::FixedDeposit, 'contribution_amount' => 1_000_00, 'cycle_length_days' => 1, 'commission_type' => CommissionType::None, 'commission_value' => 0, 'interest_rate_bps' => 1650],
        ['code' => 'SH-COOP', 'name' => 'Cooperative Share Capital', 'type' => SavingsProductType::Shares, 'contribution_amount' => 10_00, 'cycle_length_days' => 1, 'commission_type' => CommissionType::None, 'commission_value' => 0, 'par_value' => 10_00],
    ];

    /** @var array<string, int> Fixed deposit product code => term in days. */
    private const FIXED_DEPOSIT_TERMS = ['FD-091' => 91, 'FD-182' => 182];

    /** @var list<string> */
    private const DAILY_SUSU_CODES = ['DS-005', 'DS-010', 'DS-020', 'DS-050', 'WS-100'];

    public function run(
        OpenSavingsAccountAction $openAccount,
        RecordCollectionAction $recordCollection,
        BuySharesAction $buyShares,
    ): void {
        $company = Company::query()->oldest()->first();

        if (! $company) {
            $this->command?->warn('SavingsSeeder skipped: no company found. Run DemoSeeder first.');

            return;
        }

        $products = collect(self::PRODUCTS)->mapWithKeys(fn (array $attributes): array => [
            $attributes['code'] => SavingsProduct::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $attributes['code']],
                [...$attributes, 'is_active' => true],
            ),
        ]);

        $fallbackCollector = User::query()
            ->where('company_id', $company->id)
            ->role(['company_admin', 'branch_manager'])
            ->first();

        $customers = Customer::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->with(['branch', 'assignedAgent', 'savingsAccounts'])
            ->get();

        $accountsOpened = 0;

        foreach ($customers as $customer) {
            $collector = $customer->assignedAgent ?? $fallbackCollector;
            $heldProductIds = $customer->savingsAccounts->pluck('savings_product_id')->flip();

            foreach ($this->productCodesFor() as $code) {
                $product = $products[$code];

                if ($heldProductIds->has($product->id)) {
                    continue;
                }

                $account = $openAccount->execute(
                    customer: $customer,
                    product: $product,
                    agent: $customer->assignedAgent,
                    targetAmount: $product->type === SavingsProductType::Target
                        ? fake()->randomElement([1_500_00, 2_000_00, 3_000_00, 5_000_00])
                        : null,
                    maturesAt: match ($product->type) {
                        SavingsProductType::Target => now()->addMonths(fake()->numberBetween(4, 12))->startOfMonth(),
                        SavingsProductType::FixedDeposit => now()->addDays(self::FIXED_DEPOSIT_TERMS[$code]),
                        default => null,
                    },
                );
                $accountsOpened++;

                if (! $collector) {
                    continue;
                }

                match ($product->type) {
                    SavingsProductType::Shares => $buyShares->execute($collector, $account, fake()->numberBetween(5, 100)),
                    SavingsProductType::FixedDeposit => $recordCollection->execute(
                        $collector,
                        $account,
                        $account->contribution_amount * fake()->numberBetween(1, 10),
                        paymentMethod: fake()->randomElement([PaymentMethod::Cash, PaymentMethod::MobileMoney]),
                    ),
                    default => $this->recordContributions($recordCollection, $collector, $account),
                };
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d savings products and opened %d savings accounts for %d customers of "%s".',
            $products->count(),
            $accountsOpened,
            $customers->count(),
            $company->name,
        ));
    }

    /**
     * Every customer holds one daily susu account; target, fixed deposit and
     * share accounts are spread across a share of the book.
     *
     * @return list<string>
     */
    private function productCodesFor(): array
    {
        $codes = [fake()->randomElement(self::DAILY_SUSU_CODES)];

        if (fake()->boolean(30)) {
            $codes[] = fake()->randomElement(array_diff(self::DAILY_SUSU_CODES, $codes));
        }
        if (fake()->boolean(45)) {
            $codes[] = fake()->randomElement(['TS-FEES', 'TS-RENT']);
        }
        if (fake()->boolean(25)) {
            $codes[] = fake()->randomElement(array_keys(self::FIXED_DEPOSIT_TERMS));
        }
        if (fake()->boolean(60)) {
            $codes[] = 'SH-COOP';
        }

        return $codes;
    }

    /**
     * Posts a handful of deposits so the account carries a running balance,
     * a partially filled cycle and (for most products) commission entries.
     */
    private function recordContributions(RecordCollectionAction $recordCollection, User $collector, SavingsAccount $account): void
    {
        for ($deposit = fake()->numberBetween(2, 8); $deposit > 0; $deposit--) {
            $recordCollection->execute(
                $collector,
                $account->refresh(),
                $account->contribution_amount * fake()->numberBetween(1, 7),
                paymentMethod: fake()->boolean(80) ? PaymentMethod::Cash : PaymentMethod::MobileMoney,
            );
        }
    }
}
