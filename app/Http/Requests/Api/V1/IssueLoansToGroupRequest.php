<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class IssueLoansToGroupRequest extends FormRequest
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
            'principal_amount' => ['required', 'integer', 'min:1'],
            'security_deposit_amount' => ['required', 'integer', 'min:0'],
            'periodic_amount' => ['required', 'integer', 'min:1'],
            'repayment_frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'member_ids' => ['nullable', 'array', 'min:1'],
            'member_ids.*' => ['uuid', 'distinct'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
