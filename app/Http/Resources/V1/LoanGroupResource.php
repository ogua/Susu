<?php

namespace App\Http\Resources\V1;

use App\Models\LoanGroup;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanGroup
 */
class LoanGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $groupOutstanding = $this->group_outstanding_sum !== null
            ? (int) $this->group_outstanding_sum
            : $this->outstandingBalance();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'branch_id' => $this->branch_id,
            'is_active' => $this->is_active,
            'member_count' => $this->members_count ?? $this->whenLoaded('members', fn (): int => $this->members->count()),
            'group_outstanding' => $groupOutstanding,
            'group_outstanding_formatted' => Money::format($groupOutstanding),
            'members' => LoanGroupMemberResource::collection($this->whenLoaded('members')),
        ];
    }
}
