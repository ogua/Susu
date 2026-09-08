<?php

namespace App\Http\Resources\V1;

use App\Models\LoanGroupMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanGroupMember
 */
class LoanGroupMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn (): string => $this->customer->fullName()),
            'status' => $this->status,
            'joined_at' => $this->joined_at?->toISOString(),
            'active_loan' => $this->whenLoaded('activeLoan', fn () => $this->activeLoan
                ? GroupLoanResource::make($this->activeLoan)
                : null),
        ];
    }
}
