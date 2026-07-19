<?php

namespace App\Actions\GroupLoans;

use App\Enums\GroupLoanStatus;
use App\Models\Branch;
use App\Models\GroupLoan;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Snapshots the product's terms onto the group loan at application time, same
 * reasoning as ApplyForLoanAction. Requires at least 2 active loan-group
 * members (mirrors ActivateGroupAction's guard) since a "group" loan with
 * fewer than 2 joint debtors isn't a group loan. Idempotent on
 * client_reference. Borrower shares aren't created here — they're only
 * materialized at disbursement, once the roster is locked in.
 */
class ApplyForGroupLoanAction
{
    public function execute(
        User $submittedBy,
        LoanGroup $loanGroup,
        LoanProduct $product,
        int $requestedAmount,
        ?string $notes = null,
        ?string $clientReference = null,
    ): GroupLoan {
        if ($clientReference !== null) {
            $existing = GroupLoan::where('client_reference', $clientReference)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($product->company_id !== $loanGroup->company_id) {
            throw ValidationException::withMessages(['loan_product_id' => 'This product is not available for this loan group.']);
        }
        if (! $product->is_active) {
            throw ValidationException::withMessages(['loan_product_id' => 'This loan product is no longer offered.']);
        }
        if ($requestedAmount < $product->min_amount || $requestedAmount > $product->max_amount) {
            throw ValidationException::withMessages([
                'amount' => "Amount must be between {$product->min_amount} and {$product->max_amount} pesewas.",
            ]);
        }

        $activeMemberCount = $loanGroup->members()->where('status', 'active')->count();
        if ($activeMemberCount < 2) {
            throw ValidationException::withMessages(['loan_group' => 'A loan group needs at least 2 active members to apply for a group loan.']);
        }

        $branch = $loanGroup->branch;

        $groupLoan = new GroupLoan([
            'company_id' => $loanGroup->company_id,
            'branch_id' => $loanGroup->branch_id,
            'loan_group_id' => $loanGroup->id,
            'loan_product_id' => $product->id,
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
            'total_interest' => 0,
            'total_repayable' => 0,
            'outstanding_balance' => 0,
            'status' => GroupLoanStatus::Applied,
            'notes' => $notes,
            'client_reference' => $clientReference,
            'applied_at' => now(),
        ]);

        if ($clientReference !== null) {
            $groupLoan->forceFill(['id' => $clientReference]);
        }

        $groupLoan->save();

        return $groupLoan;
    }

    /** G7 numbering: {branch_code}-GL{sequence} — GL distinguishes from an individual loan's L prefix. */
    private function nextLoanNumber(Branch $branch): string
    {
        $prefix = ($branch->code ?? strtoupper(substr($branch->id, 0, 4))).'-GL';
        $sequence = GroupLoan::where('branch_id', $branch->id)->count() + 1;

        while (GroupLoan::where('company_id', $branch->company_id)
            ->where('loan_number', $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT))
            ->exists()) {
            $sequence++;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
