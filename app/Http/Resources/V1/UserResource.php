<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_url,
            'is_active' => $this->is_active,
            'role' => $this->getRoleNames()->first(),
            'company' => $this->whenLoaded('company', fn (): array => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'slug' => $this->company->slug,
                'logo' => $this->company->logo,
                'primary_color' => $this->company->primary_color,
                'secondary_color' => $this->company->secondary_color,
            ]),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'slug' => $branch->slug,
                'code' => $branch->code,
            ])),
        ];
    }
}
