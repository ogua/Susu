<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RecordLoanRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['field_agent', 'branch_manager', 'company_admin']);
    }

    /**
     * The direct HTTP route resolves the loan from the URL, so it doesn't
     * need loan_id in the body — only the sync batch payload does (no route
     * segment to carry it), hence the separate payloadRules().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
            'loan_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'recorded_at' => ['nullable', 'date'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
