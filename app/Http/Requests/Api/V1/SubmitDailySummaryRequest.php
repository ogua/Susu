<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SubmitDailySummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('field_agent');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::payloadRules();
    }

    /**
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'declared_cash' => ['required', 'integer', 'min:0'],
            'summary_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
