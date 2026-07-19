<?php

namespace App\Http\Resources\V1;

use App\Models\LoanGroup;
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'members' => LoanGroupMemberResource::collection($this->whenLoaded('members')),
        ];
    }
}
