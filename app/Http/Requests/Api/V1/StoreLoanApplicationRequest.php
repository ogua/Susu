<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanApplicationRequest extends FormRequest
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
     * validated by exactly the same rules. customer_id is only meaningful
     * for staff callers — a customer applying for themselves always has it
     * overridden to their own record by the controller.
     *
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'customer_id' => ['nullable', 'uuid'],
            'loan_product_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'savings_account_id' => ['nullable', 'uuid'],
            'guarantor_name' => ['nullable', 'string', 'max:150'],
            'guarantor_phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
