<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsAccountRequest extends FormRequest
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
        return self::payloadRules();
    }

    /**
     * Shared with the sync batch processor so offline account openings are
     * validated by exactly the same rules.
     *
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'savings_product_id' => ['required', 'uuid'],
            'contribution_amount' => ['nullable', 'integer', 'min:1'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
