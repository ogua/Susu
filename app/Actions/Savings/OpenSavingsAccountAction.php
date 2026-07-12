<?php

namespace App\Actions\Savings;

use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Ledger\ChartOfAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenSavingsAccountAction
{
    public function __construct(private ChartOfAccounts $chart) {}

    public function execute(
        Customer $customer,
        SavingsProduct $product,
        ?User $agent = null,
        ?int $contributionAmount = null,
        ?string $clientReference = null,
    ): SavingsAccount {
        if ($clientReference !== null) {
            $existing = SavingsAccount::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($product->company_id !== $customer->company_id) {
            throw ValidationException::withMessages(['product' => 'Product not available for this customer.']);
        }
        if (! $product->is_active) {
            throw ValidationException::withMessages(['product' => 'This product is no longer offered.']);
        }
        if ($agent !== null && $agent->company_id !== $customer->company_id) {
            throw ValidationException::withMessages(['agent' => 'Agent not found in this company.']);
        }

        return DB::transaction(function () use ($customer, $product, $agent, $contributionAmount, $clientReference): SavingsAccount {
            $account = new SavingsAccount([
                'company_id' => $customer->company_id,
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'savings_product_id' => $product->id,
                'agent_id' => $agent?->id,
                'account_number' => $this->nextAccountNumber($customer),
                'contribution_amount' => $contributionAmount ?? $product->contribution_amount,
                'cycle_number' => 1,
                'cycle_started_at' => now()->toDateString(),
                'contributions_this_cycle' => 0,
                'balance' => 0,
                'status' => 'active',
                'opened_at' => now(),
                'client_reference' => $clientReference,
            ]);

            // Same client-generated-id-as-primary-key pattern as CreateCustomerAction
            // (id isn't mass-assignable, hence forceFill before the first save).
            if ($clientReference !== null) {
                $account->forceFill(['id' => $clientReference]);
            }

            $account->save();

            $account->forceFill([
                'ledger_account_id' => $this->chart->savingsLiability($account)->id,
            ])->save();

            return $account;
        });
    }

    private function nextAccountNumber(Customer $customer): string
    {
        $prefix = ($customer->branch->code ?? strtoupper(substr($customer->branch_id, 0, 4))).'-S';
        $sequence = SavingsAccount::where('branch_id', $customer->branch_id)->count() + 1;

        while (SavingsAccount::where('company_id', $customer->company_id)
            ->where('account_number', $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT))
            ->exists()) {
            $sequence++;
        }

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
