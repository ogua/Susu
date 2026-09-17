<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RecordGroupLoanDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['field_agent', 'branch_manager', 'company_admin']);
    }

    /**
     * The direct route carries {groupLoan} in the URL; the sync batch needs
     * group_loan_id in the body.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'savings_account_id' => ['required', 'uuid'],
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
            'savings_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'recorded_at' => ['nullable', 'date'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
