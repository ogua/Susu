<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollectionRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'savings_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'client_reference' => ['nullable', 'uuid'],
            'recorded_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
