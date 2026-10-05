<?php

namespace App\Http\Resources\V1;

use App\Enums\ClientType;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'photo_path' => $this->photo_path,
            'status' => $this->status,
            'branch_id' => $this->branch_id,
            'has_login' => $this->user_id !== null,
            'client_type' => $this->client_type,
            'business_name' => $this->client_type === ClientType::Business ? $this->business_name : null,
            'assigned_agent_id' => $this->assigned_agent_id,
            'assigned_agent_name' => $this->whenLoaded('assignedAgent', fn (): ?string => $this->assignedAgent?->name),
            'branch_name' => $this->whenLoaded('branch', fn (): ?string => $this->branch?->name),
            'savings_balance' => $this->when(isset($this->savings_balance), fn (): int => (int) $this->savings_balance),
            'loan_balance' => $this->when(
                isset($this->loan_outstanding) || isset($this->group_loan_outstanding),
                fn (): int => (int) $this->loan_outstanding + (int) $this->group_loan_outstanding,
            ),
            'savings_accounts' => SavingsAccountResource::collection($this->whenLoaded('savingsAccounts')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
