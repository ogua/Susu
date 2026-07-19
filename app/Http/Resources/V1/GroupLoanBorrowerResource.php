<?php

namespace App\Http\Resources\V1;

use App\Models\GroupLoanBorrower;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GroupLoanBorrower
 */
class GroupLoanBorrowerResource extends JsonResource
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
            'share_principal' => $this->share_principal,
            'share_principal_formatted' => Money::format($this->share_principal),
            'share_outstanding' => $this->share_outstanding,
            'share_outstanding_formatted' => Money::format($this->share_outstanding),
        ];
    }
}
