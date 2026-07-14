<?php

namespace App\Http\Resources\V1;

use App\Models\Group;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Group
 */
class GroupResource extends JsonResource
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
            'contribution_amount' => $this->contribution_amount,
            'contribution_amount_formatted' => Money::format($this->contribution_amount),
            'frequency' => $this->frequency,
            'status' => $this->status,
            'members' => GroupMemberResource::collection($this->whenLoaded('members')),
            'rounds' => GroupRoundResource::collection($this->whenLoaded('rounds')),
            'activated_at' => $this->activated_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
