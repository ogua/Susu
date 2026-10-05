<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** Amounts are pesewas. Each row may carry a repayment, a deposit, or both. */
class PostCollectionSheetRequest extends FormRequest
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
        return [
            'date' => ['required', 'date', 'before_or_equal:today'],
            'origin' => ['nullable', 'string', 'in:mobile,desktop'],
            'payment_method' => ['nullable', 'string', 'in:cash,mobile_money'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.loan_type' => ['nullable', 'string', 'in:group,individual', 'required_with:entries.*.repayment_amount'],
            'entries.*.loan_id' => ['nullable', 'uuid', 'required_with:entries.*.repayment_amount'],
            'entries.*.repayment_amount' => ['nullable', 'integer', 'min:1'],
            'entries.*.repayment_reference' => ['nullable', 'uuid'],
            'entries.*.savings_account_id' => ['nullable', 'uuid', 'required_with:entries.*.deposit_amount'],
            'entries.*.deposit_amount' => ['nullable', 'integer', 'min:1'],
            'entries.*.deposit_reference' => ['nullable', 'uuid'],
        ];
    }
}
