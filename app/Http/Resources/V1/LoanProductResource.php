<?php

namespace App\Http\Resources\V1;

use App\Models\LoanProduct;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanProduct
 */
class LoanProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'interest_method' => $this->interest_method,
            'interest_rate_bps' => $this->interest_rate_bps,
            'term_period_count' => $this->term_period_count,
            'repayment_frequency' => $this->repayment_frequency,
            'origination_fee_amount' => $this->origination_fee_amount,
            'min_amount' => $this->min_amount,
            'min_amount_formatted' => Money::format($this->min_amount),
            'max_amount' => $this->max_amount,
            'max_amount_formatted' => Money::format($this->max_amount),
        ];
    }
}
