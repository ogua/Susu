<?php

namespace App\Http\Resources\V1;

use App\Models\GroupRound;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GroupRound
 */
class GroupRoundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'round_number' => $this->round_number,
            'payout_member' => $this->whenLoaded('payoutMember', fn (): array => [
                'id' => $this->payoutMember->id,
                'customer_id' => $this->payoutMember->customer_id,
                'customer_name' => $this->payoutMember->customer->fullName(),
            ]),
            'due_date' => $this->due_date?->toDateString(),
            'total_expected' => $this->total_expected,
            'total_expected_formatted' => Money::format($this->total_expected),
            'total_collected' => $this->total_collected,
            'total_collected_formatted' => Money::format($this->total_collected),
            'status' => $this->status,
            'paid_out_at' => $this->paid_out_at?->toISOString(),
        ];
    }
}
