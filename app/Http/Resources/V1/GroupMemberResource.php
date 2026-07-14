<?php

namespace App\Http\Resources\V1;

use App\Models\GroupMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GroupMember
 */
class GroupMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn (): string => $this->customer->fullName()),
            'rotation_position' => $this->rotation_position,
            'status' => $this->status,
        ];
    }
}
