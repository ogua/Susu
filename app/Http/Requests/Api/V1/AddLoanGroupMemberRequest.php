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

    /**
     * loan_group.member.add / loan_group.member.remove sync ops. Members are
     * named by group + customer (stable across devices), not a member id.
     *
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'loan_group_id' => ['required', 'uuid'],
            'customer_id' => ['required', 'uuid'],
        ];
    }
}
