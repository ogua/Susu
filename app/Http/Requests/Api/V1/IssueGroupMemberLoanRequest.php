<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class IssueGroupMemberLoanRequest extends FormRequest
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
        return self::payloadRules();
    }

    /**
     * Shared with the sync batch processor so offline-issued loans are
     * validated by exactly the same rules.
     *
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'loan_group_id' => ['required', 'uuid'],
            'customer_id' => ['required', 'uuid'],
            'principal_amount' => ['required', 'integer', 'min:1'],
            'security_deposit_amount' => ['required', 'integer', 'min:0'],
            'periodic_amount' => ['required', 'integer', 'min:1'],
            'repayment_frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
