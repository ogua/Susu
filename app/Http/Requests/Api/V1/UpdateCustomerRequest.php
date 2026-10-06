<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class UpdateCustomerRequest extends FormRequest
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
        return Arr::except(self::payloadRules(), ['customer_id']);
    }

    /**
     * The registration rules made partial: only the keys sent are changed.
     * Branch and agent are left out because transfer and agent assignment
     * have their own flows, and client_reference is fixed at registration.
     *
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        $rules = Arr::except(StoreCustomerRequest::payloadRules(), ['client_reference', 'branch_id', 'assigned_agent_id']);

        $partial = array_map(
            fn (array $fieldRules): array => array_map(
                fn (mixed $rule): mixed => $rule === 'required' ? 'sometimes' : $rule,
                $fieldRules,
            ),
            $rules,
        );

        return ['customer_id' => ['required', 'uuid'], ...$partial];
    }
}
