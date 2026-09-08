<?php

namespace App\Http\Resources\V1;

use App\Models\GroupLoanRepayment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GroupLoanRepayment
 */
class GroupLoanRepaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'amount_formatted' => Money::format($this->amount),
            'recorded_at' => $this->recorded_at?->toISOString(),
        ];
    }
}
