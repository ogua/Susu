<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BuySharesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['field_agent', 'branch_manager', 'company_admin']);
    }

    /**
     * Only used via the sync batch (shares.purchase) — there's no direct HTTP route for
     * this op, so only payloadRules() applies.
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
            'savings_account_id' => ['required', 'uuid'],
            'shares' => ['required', 'integer', 'min:1'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
