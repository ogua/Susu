<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AddGroupMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['branch_manager', 'company_admin']);
    }

    /**
     * Only used via the sync batch (group.member.add) — there's no direct HTTP route for
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
            'group_id' => ['required', 'uuid'],
            'customer_id' => ['required', 'uuid'],
            'rotation_position' => ['required', 'integer', 'min:1'],
            // Becomes the member's id, so later group.contribution.record ops resolve it.
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
