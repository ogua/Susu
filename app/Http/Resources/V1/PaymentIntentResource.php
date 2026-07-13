<?php

namespace App\Http\Resources\V1;

use App\Models\PaymentIntent;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentIntent
 */
class PaymentIntentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow' => $this->flow,
            'channel' => $this->channel,
            'phone' => $this->phone,
            'amount' => $this->amount,
            'amount_formatted' => Money::format($this->amount),
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
            'authorization_url' => $this->raw_response['data']['authorization_url'] ?? null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
