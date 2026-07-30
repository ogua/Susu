<?php

namespace App\Http\Resources\V1;

use App\Models\SavingsProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product catalogue shape — consumed by offline clients (desktop hybrid
 * mode) to keep their local savings_products table id-consistent with the
 * server's, so account.open sync ops resolve correctly (see
 * App\Actions\Savings\OpenSavingsAccountAction).
 *
 * @mixin SavingsProduct
 */
class SavingsProductResource extends JsonResource
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
            'type' => $this->type,
            'contribution_amount' => $this->contribution_amount,
            'cycle_length_days' => $this->cycle_length_days,
            'commission_type' => $this->commission_type,
            'commission_value' => $this->commission_value,
            'interest_rate_bps' => $this->interest_rate_bps,
            'par_value' => $this->par_value,
            'is_active' => $this->is_active,
        ];
    }
}
