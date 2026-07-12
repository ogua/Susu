<?php

namespace App\Http\Resources\V1;

use App\Models\SavingsAccount;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavingsAccount
 */
class SavingsAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_number' => $this->account_number,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'product' => $this->whenLoaded('product', fn (): array => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'type' => $this->product->type,
                'cycle_length_days' => $this->product->cycle_length_days,
            ]),
            'agent_id' => $this->agent_id,
            'contribution_amount' => $this->contribution_amount,
            'contribution_formatted' => Money::format($this->contribution_amount),
            'cycle_number' => $this->cycle_number,
            'contributions_this_cycle' => $this->contributions_this_cycle,
            'balance' => $this->balance,
            'balance_formatted' => Money::format($this->balance),
            'status' => $this->status,
            'opened_at' => $this->opened_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
