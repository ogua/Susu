<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationPingsRequest extends FormRequest
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
            'pings' => ['required', 'array', 'min:1', 'max:240'],
            'pings.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'pings.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'pings.*.accuracy' => ['nullable', 'numeric', 'min:0'],
            'pings.*.recorded_at' => ['required', 'date'],
        ];
    }
}
