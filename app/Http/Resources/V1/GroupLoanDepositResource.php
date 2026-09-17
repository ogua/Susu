<?php

namespace App\Http\Resources\V1;

use App\Models\GroupLoanDeposit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GroupLoanDeposit
 */
class GroupLoanDepositResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'savings_account_id' => $this->savings_account_id,
            'amount' => $this->amount,
            'amount_formatted' => Money::format($this->amount),
            'type' => $this->type,
            'recorded_at' => $this->recorded_at?->toISOString(),
        ];
    }
}
