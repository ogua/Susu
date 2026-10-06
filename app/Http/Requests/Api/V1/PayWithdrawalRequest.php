<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class PayWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['branch_manager', 'company_admin']);
    }

    /**
     * Only used via the sync batch (withdrawal.pay) — there's no direct HTTP route for
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
            'withdrawal_request_id' => ['required', 'uuid'],
        ];
    }
}
