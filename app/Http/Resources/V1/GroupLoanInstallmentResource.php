<?php

namespace App\Http\Resources\V1;

use App\Models\GroupLoanInstallment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GroupLoanInstallment
 */
class GroupLoanInstallmentResource extends JsonResource
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
            'amount_due' => $this->amount_due,
            'amount_due_formatted' => Money::format($this->amount_due),
            'amount_paid' => $this->amount_paid,
            'amount_paid_formatted' => Money::format($this->amount_paid),
            'remaining' => $this->remaining(),
            'remaining_formatted' => Money::format($this->remaining()),
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toISOString(),
        ];
    }
}
