<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RecordGroupLoanRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['field_agent', 'branch_manager', 'company_admin']);
    }

    /**
     * Unlike RecordLoanRepaymentRequest, the direct route only carries
     * {groupLoan} — it never identifies which member paid, so
     * group_loan_borrower_id must be in both rules() and payloadRules().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'group_loan_borrower_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'recorded_at' => ['nullable', 'date'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'group_loan_id' => ['required', 'uuid'],
            'group_loan_borrower_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'recorded_at' => ['nullable', 'date'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
