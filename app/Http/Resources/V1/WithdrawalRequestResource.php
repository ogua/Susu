<?php

namespace App\Http\Resources\V1;

use App\Models\WithdrawalRequest;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WithdrawalRequest
 */
class WithdrawalRequestResource extends JsonResource
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
            'reason' => $this->reason,
            'status' => $this->status,
            'rejected_reason' => $this->rejected_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
