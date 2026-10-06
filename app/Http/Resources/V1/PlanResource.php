<?php

namespace App\Http\Resources\V1;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan a company can switch to. Amounts are minor units; a null limit is
 * unlimited.
 *
 * @mixin Plan
 */
class PlanResource extends JsonResource
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
            'description' => $this->description,
            'price_amount' => $this->price_amount,
            'currency' => $this->currency,
            'billing_period' => $this->billing_period->value,
            'trial_days' => $this->trial_days,
            'max_branches' => $this->max_branches,
            'max_staff' => $this->max_staff,
            'max_customers' => $this->max_customers,
        ];
    }
}
