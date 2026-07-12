<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SyncOpType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['field_agent', 'branch_manager', 'company_admin']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ops' => ['required', 'array', 'min:1', 'max:500'],
            'ops.*.op_id' => ['required', 'uuid', 'distinct'],
            'ops.*.op_type' => ['required', Rule::enum(SyncOpType::class)],
            'ops.*.payload' => ['required', 'array'],
            'ops.*.recorded_at' => ['required', 'date'],
        ];
    }
}
