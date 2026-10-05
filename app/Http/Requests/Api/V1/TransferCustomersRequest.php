<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** Single (customer in the URL) or bulk (customer_ids in the body) branch transfer. */
class TransferCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['branch_manager', 'company_admin']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid'],
            'agent_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
            'customer_ids' => [$this->route('customer') ? 'prohibited' : 'required', 'array', 'min:1', 'max:500'],
            'customer_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
