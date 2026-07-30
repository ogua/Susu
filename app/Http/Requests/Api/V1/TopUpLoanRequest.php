<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TopUpLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['branch_manager', 'company_admin']);
    }

    /**
     * Only used via the sync batch — there's no direct HTTP route for this
     * op, so only payloadRules() (which needs loan_id in the body) applies.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'loan_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
