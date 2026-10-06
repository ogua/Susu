<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LoanFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['branch_manager', 'company_admin']);
    }

    /**
     * Only used via the sync batch (group.create) — there's no direct HTTP route for
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
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
            'contribution_amount' => ['required', 'integer', 'min:1'],
            'frequency' => ['required', Rule::enum(LoanFrequency::class)],
            // Branch to create in; defaults to the caller's working branch (StaffBranch).
            'branch_id' => ['nullable', 'uuid'],
            // Becomes the group's id, so later member/activate/payout ops resolve it.
            'client_reference' => ['nullable', 'uuid'],
        ];
    }
}
