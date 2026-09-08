<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AddLoanGroupMemberRequest extends FormRequest
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
            'customer_id' => ['required', 'uuid'],
        ];
    }
}
