<?php

namespace App\Http\Resources\V1;

use App\Models\JournalEntry;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The mobile/desktop "transaction" shape of a journal entry.
 *
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $amount = (int) ($this->meta['amount'] ?? $this->amount());

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'amount' => $amount,
            'amount_formatted' => Money::format($amount),
            'balance_after' => $this->meta['balance_after'] ?? null,
            'description' => $this->description,
            'recorded_at' => $this->recorded_at?->toISOString(),
            'posted_at' => $this->posted_at?->toISOString(),
        ];
    }
}
