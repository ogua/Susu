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
            'loan_group_member_id' => $this->loan_group_member_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn (): string => $this->customer->fullName()),
            'principal_amount' => $this->principal_amount,
            'principal_amount_formatted' => Money::format($this->principal_amount),
            'security_deposit_amount' => $this->security_deposit_amount,
            'security_deposit_amount_formatted' => Money::format($this->security_deposit_amount),
            'periodic_amount' => $this->periodic_amount,
            'periodic_amount_formatted' => Money::format($this->periodic_amount),
            'repayment_frequency' => $this->repayment_frequency,
            'start_date' => $this->start_date?->toDateString(),
            'total_periods' => $this->total_periods,
            'outstanding_balance' => $this->outstanding_balance,
            'outstanding_balance_formatted' => Money::format($this->outstanding_balance),
            'amount_repaid' => $this->amountRepaid(),
            'amount_repaid_formatted' => Money::format($this->amountRepaid()),
            'deposit_status' => $this->deposit_status,
            'status' => $this->status,
            'installments' => GroupLoanInstallmentResource::collection($this->whenLoaded('installments')),
            'repayments' => GroupLoanRepaymentResource::collection($this->whenLoaded('repayments')),
            'deposits' => GroupLoanDepositResource::collection($this->whenLoaded('deposits')),
            'issued_at' => $this->issued_at?->toISOString(),
            'activated_at' => $this->activated_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'written_off_at' => $this->written_off_at?->toISOString(),
            'write_off_reason' => $this->write_off_reason,
            'write_off_amount' => $this->write_off_amount,
            'write_off_savings_account_id' => $this->write_off_savings_account_id,
            'write_off_savings_applied' => $this->write_off_savings_applied,
        ];
    }
}
