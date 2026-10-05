<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\Ledger\ChartOfAccounts;
use App\Services\Ledger\EntryData;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsAccount>
 */
class SavingsAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::find($attributes['branch_id'])->company_id,
            'customer_id' => fn (array $attributes) => Customer::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'company_id' => Branch::find($attributes['branch_id'])->company_id,
            ])->id,
            'savings_product_id' => fn (array $attributes) => SavingsProduct::factory()->create([
                'company_id' => Branch::find($attributes['branch_id'])->company_id,
            ])->id,
            'account_number' => strtoupper(fake()->unique()->bothify('SA-######')),
            'contribution_amount' => 500,
            'cycle_number' => 1,
            'cycle_started_at' => now()->toDateString(),
            'contributions_this_cycle' => 0,
            'balance' => 0,
            'status' => 'active',
            'opened_at' => now(),
        ];
    }

    /**
     * Attach the liability ledger sub-account after creation so the account
     * can post entries immediately.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (SavingsAccount $account): void {
            if ($account->ledger_account_id === null) {
                $account->forceFill([
                    'ledger_account_id' => app(ChartOfAccounts::class)->savingsLiability($account)->id,
                ])->save();
            }
        });
    }

    /**
     * Give the account a balance backed by a real ledger entry (Dr branch
     * cash / Cr savings liability), so ledger:verify-balances stays clean.
     */
    public function funded(int $amount): static
    {
        return $this->afterCreating(function (SavingsAccount $account) use ($amount): void {
            $chart = app(ChartOfAccounts::class);
            $account->refresh();

            app(LedgerService::class)->post(new EntryData(
                company: $account->company,
                type: TransactionType::Adjustment,
                lines: [
                    ['account' => $chart->branchCash($account->branch), 'debit' => $amount],
                    ['account' => $account->ledgerAccount, 'credit' => $amount],
                ],
                branch: $account->branch,
                description: 'Opening balance (test)',
            ));

            $account->forceFill(['balance' => $account->balance + $amount])->save();
        });
    }
}
