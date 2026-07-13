<?php

namespace App\Http\Resources\V1;

use App\Models\LoanInstallment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanInstallment
 */
class LoanInstallmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'due_date' => $this->due_date?->toDateString(),
            'principal_due' => $this->principal_due,
            'interest_due' => $this->interest_due,
            'penalty_due' => $this->penalty_due,
            'total_due' => $this->totalDue(),
            'total_due_formatted' => Money::format($this->totalDue()),
            'amount_paid' => $this->amountPaid(),
            'remaining' => $this->remaining(),
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toISOString(),
        ];
    }
}
