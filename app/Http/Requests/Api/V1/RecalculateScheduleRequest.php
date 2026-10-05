<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-date a running loan's unpaid installments (RecalculateRepaymentScheduleAction).
 * Shared by the loan and group-loan routes and their sync ops.
 */
class RecalculateScheduleRequest extends FormRequest
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
        return self::fieldRules();
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            // Omit to re-derive dates from the loan's own rule.
            'first_due_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * loan.schedule.recalculate sync payload.
     *
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return ['loan_id' => ['required', 'uuid'], ...self::fieldRules()];
    }

    /**
     * group_loan.schedule.recalculate sync payload.
     *
     * @return array<string, mixed>
     */
    public static function groupPayloadRules(): array
    {
        return ['group_loan_id' => ['required', 'uuid'], ...self::fieldRules()];
    }
}
