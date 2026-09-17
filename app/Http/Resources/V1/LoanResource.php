<?php

namespace App\Http\Resources\V1;

use App\Models\Loan;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_number' => $this->loan_number,
            'customer_id' => $this->customer_id,
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
            'status' => $this->status,
            'guarantor_name' => $this->guarantor_name,
            'guarantor_phone' => $this->guarantor_phone,
            'rejection_reason' => $this->rejection_reason,
            'installments' => LoanInstallmentResource::collection($this->whenLoaded('installments')),
            'applied_at' => $this->applied_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'disbursed_at' => $this->disbursed_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'written_off_at' => $this->written_off_at?->toISOString(),
            'write_off_reason' => $this->write_off_reason,
            'write_off_amount' => $this->write_off_amount,
            'write_off_savings_account_id' => $this->write_off_savings_account_id,
            'write_off_savings_applied' => $this->write_off_savings_applied,
        ];
    }
}
