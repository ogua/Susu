<?php

namespace App\Actions\Loans;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Snapshots the product's terms onto the loan at application time (not
 * approval/disbursement) so a loan officer reviewing an application always
 * sees the terms the customer actually applied under, even if the product
 * changes before a decision is made. Idempotent on client_reference.
 */
class ApplyForLoanAction
{
    public function execute(
        User $submittedBy,
        Customer $customer,
        LoanProduct $product,
        int $requestedAmount,
        ?SavingsAccount $savingsAccount = null,
        ?string $guarantorName = null,
        ?string $guarantorPhone = null,
        ?string $notes = null,
        ?string $clientReference = null,
    ): Loan {
        if ($clientReference !== null) {
            $existing = Loan::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($product->company_id !== $customer->company_id) {
            throw ValidationException::withMessages(['loan_product_id' => 'This product is not available for this customer.']);
        }
        if (! $product->is_active) {
            throw ValidationException::withMessages(['loan_product_id' => 'This loan product is no longer offered.']);
        }
        if ($requestedAmount < $product->min_amount || $requestedAmount > $product->max_amount) {
            throw ValidationException::withMessages([
                'amount' => "Amount must be between {$product->min_amount} and {$product->max_amount} pesewas.",
            ]);
        }
        if ($savingsAccount !== null && $savingsAccount->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['savings_account_id' => 'This account does not belong to the customer.']);
        }

        $branch = $customer->branch;

        $loan = new Loan([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'savings_account_id' => $savingsAccount?->id,
            'agent_id' => $submittedBy->hasRole('field_agent') ? $submittedBy->id : null,
            'loan_number' => $this->nextLoanNumber($branch),
            'principal_amount' => $requestedAmount,
            'interest_method' => $product->interest_method,
            'interest_rate_bps' => $product->interest_rate_bps,
            'term_period_count' => $product->term_period_count,
            'repayment_frequency' => $product->repayment_frequency,
            'origination_fee_amount' => $product->origination_fee_amount,
            'penalty_rate_bps' => $product->penalty_rate_bps,
            'grace_period_days' => $product->grace_period_days,
            // Explicit rather than relying on the DB column defaults: Eloquent
            // never reflects those back onto the in-memory model create()
            // returns, so callers immediately serializing this loan (e.g. the
            // API response) would otherwise see null instead of 0.
            'total_interest' => 0,
            'total_repayable' => 0,
            'outstanding_balance' => 0,
            'status' => LoanStatus::Applied,
            'guarantor_name' => $guarantorName,
            'guarantor_phone' => $guarantorPhone,
            'notes' => $notes,
            'client_reference' => $clientReference,
            'applied_at' => now(),
        ]);

        // Offline clients (desktop/mobile) generate this UUID themselves; using
        // it as the primary key too (id isn't mass-assignable, hence forceFill)
        // means the record's identity matches across the client that created
        // it and the server, so later ops in the same lifecycle (approve,
        // disburse, repayment) referencing this loan_id resolve correctly once
        // synced. Must be set before the first save() so HasUuids' creating()
        // hook sees it and skips auto-generation. Mirrors CreateCustomerAction.
        if ($clientReference !== null) {
            $loan->forceFill(['id' => $clientReference]);
        }

        $loan->save();

        return $loan;
    }

    /** G7 numbering: {branch_code}-L{sequence}, opaque and unique — UUIDs remain the real identity. */
    private function nextLoanNumber(Branch $branch): string
    {
        $prefix = ($branch->code ?? strtoupper(substr($branch->id, 0, 4))).'-L';
        $sequence = Loan::where('branch_id', $branch->id)->count() + 1;

        while (Loan::where('company_id', $branch->company_id)
            ->where('loan_number', $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT))
            ->exists()) {
            $sequence++;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
