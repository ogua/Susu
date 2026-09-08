<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('loan_groups', 'code')->where('company_id', $this->user()->company_id),
            ],
        ];
    }
}
