<?php

namespace App\Http\Resources\V1;

use App\Models\AgentDailySummary;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentDailySummary
 */
class AgentDailySummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'summary_date' => $this->summary_date,
            'collections_total' => $this->collections_total,
            'collections_total_formatted' => Money::format($this->collections_total),
            'collections_count' => $this->collections_count,
            'expected_cash' => $this->expected_cash,
            'declared_cash' => $this->declared_cash,
            'variance' => $this->variance,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
