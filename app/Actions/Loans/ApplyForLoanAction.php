<?php

namespace App\Actions\Loans;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        ?LoanApplicationDetails $details = null,
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

        $details ??= new LoanApplicationDetails;
        $this->assertDetails($submittedBy, $customer, $details);

        $firstGuarantor = $details->guarantors[0] ?? null;
        $originationFee = $details->charges !== null
            ? array_sum(array_column($details->charges, 'amount'))
            : $product->origination_fee_amount;

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
            'interest_method' => $details->interestMethod ?? $product->interest_method,
            'interest_rate_bps' => $details->interestRateBps ?? $product->interest_rate_bps,
            'term_period_count' => $details->termPeriodCount ?? $product->term_period_count,
            'repayment_frequency' => $details->repaymentFrequency ?? $product->repayment_frequency,
            'origination_fee_amount' => $originationFee,
            'penalty_rate_bps' => $product->penalty_rate_bps,
            'grace_period_days' => $details->gracePeriodDays ?? $product->grace_period_days,
            'first_repayment_date' => $details->firstRepaymentDate,
            'purpose' => $details->purpose,
            // Explicit rather than relying on the DB column defaults: Eloquent
            // never reflects those back onto the in-memory model create()
            // returns, so callers immediately serializing this loan (e.g. the
            // API response) would otherwise see null instead of 0.
            'total_interest' => 0,
            'total_repayable' => 0,
            'outstanding_balance' => 0,
            'status' => LoanStatus::Applied,
            // Legacy single-guarantor columns, mirrored from the first itemised
            // guarantor so older clients reading them still see one.
            'guarantor_name' => $guarantorName ?? $firstGuarantor['name'] ?? null,
            'guarantor_phone' => $guarantorPhone ?? $firstGuarantor['phone'] ?? null,
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

        return DB::transaction(function () use ($loan, $details, $product): Loan {
            $loan->save();

            $charges = $details->charges ?? ($product->origination_fee_amount > 0
                ? [['name' => 'Processing fee', 'amount' => $product->origination_fee_amount]]
                : []);
            foreach (array_filter($charges, fn (array $charge): bool => $charge['amount'] > 0) as $charge) {
                $loan->charges()->create($charge);
            }
            foreach ($details->collaterals as $collateral) {
                $loan->collaterals()->create(Arr::only($collateral, ['type', 'description', 'estimated_value', 'serial_number', 'notes']) + ['estimated_value' => 0]);
            }
            foreach ($details->guarantors as $guarantor) {
                $loan->guarantors()->create(Arr::only($guarantor, ['customer_id', 'name', 'phone', 'relationship', 'address', 'id_type', 'id_number', 'guaranteed_amount']));
            }

            return $loan;
        });
    }

    private function assertDetails(User $submittedBy, Customer $customer, LoanApplicationDetails $details): void
    {
        if ($details->overridesTerms() && ! $submittedBy->hasRole(['field_agent', 'branch_manager', 'company_admin'])) {
            throw ValidationException::withMessages(['term_period_count' => 'Only staff can change a product\'s terms or charges.']);
        }

        $guarantorCustomerIds = array_filter(array_column($details->guarantors, 'customer_id'));
        if (in_array($customer->id, $guarantorCustomerIds, true)) {
            throw ValidationException::withMessages(['guarantors' => 'A customer cannot guarantee their own loan.']);
        }
        if ($guarantorCustomerIds !== [] && Customer::whereIn('id', $guarantorCustomerIds)->where('company_id', $customer->company_id)->count() !== count(array_unique($guarantorCustomerIds))) {
            throw ValidationException::withMessages(['guarantors' => 'Guarantor not found.']);
        }
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
