<?php

namespace App\Http\Resources\V1;

use App\Models\GroupLoan;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GroupLoan
 */
class GroupLoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_number' => $this->loan_number,
            'loan_group_id' => $this->loan_group_id,
            'loan_group' => $this->whenLoaded('loanGroup', fn (): array => [
                'id' => $this->loanGroup->id,
                'name' => $this->loanGroup->name,
            ]),
            'loan_product' => $this->whenLoaded('loanProduct', fn (): array => [
                'id' => $this->loanProduct->id,
                'name' => $this->loanProduct->name,
            ]),
            'principal_amount' => $this->principal_amount,
            'principal_amount_formatted' => Money::format($this->principal_amount),
            'interest_method' => $this->interest_method,
            'interest_rate_bps' => $this->interest_rate_bps,
            'term_period_count' => $this->term_period_count,
            'repayment_frequency' => $this->repayment_frequency,
            'total_interest' => $this->total_interest,
            'total_repayable' => $this->total_repayable,
            'total_repayable_formatted' => Money::format($this->total_repayable),
            'outstanding_balance' => $this->outstanding_balance,
            'outstanding_balance_formatted' => Money::format($this->outstanding_balance),
            'member_count_at_disbursement' => $this->member_count_at_disbursement,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'borrowers' => GroupLoanBorrowerResource::collection($this->whenLoaded('borrowers')),
            'installments' => GroupLoanInstallmentResource::collection($this->whenLoaded('installments')),
            'applied_at' => $this->applied_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'disbursed_at' => $this->disbursed_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
        ];
    }
}
