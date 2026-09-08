<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class WriteOffGroupLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['branch_manager', 'company_admin']);
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'group_loan_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
