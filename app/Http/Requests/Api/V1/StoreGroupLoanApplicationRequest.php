<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreGroupLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::payloadRules();
    }

    /**
     * Shared with the sync batch processor so offline applications are
     * validated by exactly the same rules.
     *
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'loan_group_id' => ['required', 'uuid'],
            'loan_product_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
