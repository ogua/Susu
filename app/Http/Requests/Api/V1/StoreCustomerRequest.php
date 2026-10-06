<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BusinessIncomeLevel;
use App\Enums\BusinessSector;
use App\Enums\BusinessStructure;
use App\Enums\ClientType;
use App\Enums\IdentificationType;
use App\Enums\MaritalStatus;
use App\Enums\ResidencyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
     * Shared with the sync batch processor so offline registrations are
     * validated by exactly the same rules.
     *
     * @return array<string, mixed>
     */
    public static function payloadRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32'],
            'gender' => ['nullable', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'id_type' => ['nullable', 'string', 'max:30'],
            'id_number' => ['nullable', 'string', 'max:60'],
            'next_of_kin_name' => ['nullable', 'string', 'max:150'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:32'],
            'next_of_kin_relationship' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'client_reference' => ['nullable', 'uuid'],
            // Branch to register into; defaults to the caller's working branch (StaffBranch).
            'branch_id' => ['nullable', 'uuid'],

            // eBanQR-parity KYC fields — all additive/nullable so existing
            // mobile/desktop clients that don't send them are unaffected.
            'client_type' => ['nullable', Rule::enum(ClientType::class)],
            'external_id' => ['nullable', 'string', 'max:60'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'city_town' => ['nullable', 'string', 'max:120'],
            'state_region' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'digital_address' => ['nullable', 'string', 'max:40'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'spouse_name' => ['nullable', 'string', 'max:255'],
            'spouse_date_of_birth' => ['nullable', 'date'],
            'spouse_occupation' => ['nullable', 'string', 'max:120'],
            'has_past_loan' => ['nullable', 'boolean'],
            'past_loan_institution' => ['nullable', 'string', 'max:150'],
            'spouse_employer_name' => ['nullable', 'string', 'max:255'],
            'spouse_employer_address' => ['nullable', 'string', 'max:500'],
            'spouse_employer_town' => ['nullable', 'string', 'max:120'],
            'spouse_employer_county' => ['nullable', 'string', 'max:120'],
            'spouse_employer_region' => ['nullable', 'string', 'max:120'],
            'religion' => ['nullable', 'string', 'max:60'],

            'business_name' => ['required_if:client_type,business', 'nullable', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:32'],
            'business_tin' => ['nullable', 'string', 'max:40'],
            'business_line' => ['nullable', Rule::enum(BusinessSector::class)],
            'business_structure' => ['required_if:client_type,business', 'nullable', Rule::enum(BusinessStructure::class)],
            'business_start_date' => ['required_if:client_type,business', 'nullable', 'date'],
            'business_income_level' => ['nullable', Rule::enum(BusinessIncomeLevel::class)],
            'business_address' => ['nullable', 'string', 'max:500'],
            'business_town' => ['nullable', 'string', 'max:120'],
            'business_county' => ['nullable', 'string', 'max:120'],
            'business_region' => ['nullable', 'string', 'max:120'],
            'business_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'business_longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'tin' => ['nullable', 'string', 'max:40'],
            'other_names' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'country_of_residence' => ['nullable', 'string', 'max:80'],
            'residence_permit' => ['nullable', 'string', 'max:60'],
            'residency_status' => ['nullable', Rule::enum(ResidencyStatus::class)],
            'assigned_agent_id' => ['nullable', 'uuid'],

            'identifications' => ['nullable', 'array'],
            'identifications.*.id' => ['nullable', 'uuid'],
            'identifications.*.id_type' => ['required_with:identifications', Rule::enum(IdentificationType::class)],
            'identifications.*.id_number' => ['required_with:identifications', 'string', 'max:60'],
            'identifications.*.issue_date' => ['nullable', 'date'],
            'identifications.*.expiry_date' => ['nullable', 'date', 'after_or_equal:identifications.*.issue_date'],
            'identifications.*.description' => ['nullable', 'string', 'max:255'],
            'identifications.*.is_primary' => ['nullable', 'boolean'],

            'beneficiaries' => ['nullable', 'array'],
            'beneficiaries.*.id' => ['nullable', 'uuid'],
            'beneficiaries.*.name' => ['required_with:beneficiaries', 'string', 'max:150'],
            'beneficiaries.*.relationship' => ['nullable', 'string', 'max:60'],
            'beneficiaries.*.amount_of_legacy' => ['nullable', 'integer', 'min:0'],
            'beneficiaries.*.phone' => ['nullable', 'string', 'max:32'],
            'beneficiaries.*.address' => ['nullable', 'string', 'max:500'],
            'beneficiaries.*.town' => ['nullable', 'string', 'max:120'],
            'beneficiaries.*.county' => ['nullable', 'string', 'max:120'],
            'beneficiaries.*.state_region' => ['nullable', 'string', 'max:120'],

            'family_members' => ['nullable', 'array'],
            'family_members.*.id' => ['nullable', 'uuid'],
            'family_members.*.name' => ['required_with:family_members', 'string', 'max:150'],
            'family_members.*.relationship' => ['nullable', 'string', 'max:60'],
            'family_members.*.contact_phone' => ['nullable', 'string', 'max:32'],
            'family_members.*.occupation' => ['nullable', 'string', 'max:120'],
        ];
    }
}
