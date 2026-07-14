<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreGroupContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['field_agent', 'branch_manager', 'company_admin']);
    }

    /**
     * The route only carries {group}, not the member — group_member_id is
     * always in the body, for the direct HTTP call and the sync payload
     * alike, hence payloadRules() below is identical.
     *
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
            'group_member_id' => ['required', 'uuid'],
            'recorded_at' => ['nullable', 'date'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
