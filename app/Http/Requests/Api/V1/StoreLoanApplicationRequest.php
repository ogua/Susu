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
        return [
            ...self::payloadRules(),
            'first_repayment_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
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
            ...self::detailRules(),
        ];
    }

    /**
     * Optional application-wizard details (LoanApplicationDetails). Term
     * overrides are staff-only — ApplyForLoanAction rejects them from customers.
     *
     * @return array<string, mixed>
     */
    public static function detailRules(): array
    {
        return [
            'term_period_count' => ['nullable', 'integer', 'min:1', 'max:520'],
            'repayment_frequency' => ['nullable', 'string', 'in:daily,weekly,monthly'],
            'interest_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'interest_method' => ['nullable', 'string', 'in:flat,reducing_balance'],
            'grace_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // No "not in the past" rule here: an offline application replayed
            // days later must still sync. Direct requests add it in rules().
            'first_repayment_date' => ['nullable', 'date'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'charges' => ['nullable', 'array', 'max:20'],
            'charges.*.name' => ['required', 'string', 'max:120'],
            'charges.*.amount' => ['required', 'integer', 'min:0'],
            'collaterals' => ['nullable', 'array', 'max:20'],
            'collaterals.*.type' => ['required', 'string', 'max:60'],
            'collaterals.*.description' => ['required', 'string', 'max:255'],
            'collaterals.*.estimated_value' => ['nullable', 'integer', 'min:0'],
            'collaterals.*.serial_number' => ['nullable', 'string', 'max:120'],
            'collaterals.*.notes' => ['nullable', 'string', 'max:1000'],
            'guarantors' => ['nullable', 'array', 'max:10'],
            'guarantors.*.name' => ['required', 'string', 'max:150'],
            'guarantors.*.customer_id' => ['nullable', 'uuid'],
            'guarantors.*.phone' => ['nullable', 'string', 'max:32'],
            'guarantors.*.relationship' => ['nullable', 'string', 'max:60'],
            'guarantors.*.address' => ['nullable', 'string', 'max:500'],
            'guarantors.*.id_type' => ['nullable', 'string', 'max:30'],
            'guarantors.*.id_number' => ['nullable', 'string', 'max:60'],
            'guarantors.*.guaranteed_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
