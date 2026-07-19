<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\BusinessIncomeLevel;
use App\Enums\BusinessSector;
use App\Enums\BusinessStructure;
use App\Enums\ClientType;
use App\Enums\MaritalStatus;
use App\Enums\ResidencyStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'user_id',
        'customer_code',
        'first_name',
        'last_name',
        'phone',
        'gender',
        'date_of_birth',
        'id_type',
        'id_number',
        'id_photo_path',
        'photo_path',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'address',
        'status',
        'client_reference',
        'registered_by',
        'client_type',
        'external_id',
        'place_of_birth',
        'nationality',
        'email',
        'city_town',
        'state_region',
        'country',
        'digital_address',
        'latitude',
        'longitude',
        'marital_status',
        'spouse_name',
        'spouse_date_of_birth',
        'spouse_occupation',
        'has_past_loan',
        'past_loan_institution',
        'spouse_employer_name',
        'spouse_employer_address',
        'spouse_employer_town',
        'spouse_employer_county',
        'spouse_employer_region',
        'religion',
        'business_name',
        'business_phone',
        'business_tin',
        'business_line',
        'business_structure',
        'business_start_date',
        'business_income_level',
        'business_address',
        'business_town',
        'business_county',
        'business_region',
        'business_latitude',
        'business_longitude',
        'tin',
        'other_names',
        'occupation',
        'job_title',
        'country_of_residence',
        'residence_permit',
        'residency_status',
        'assigned_agent_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'id_number' => 'encrypted',
            'status' => AccountStatus::class,
            'client_type' => ClientType::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'marital_status' => MaritalStatus::class,
            'spouse_date_of_birth' => 'date',
            'has_past_loan' => 'boolean',
            'business_structure' => BusinessStructure::class,
            'business_line' => BusinessSector::class,
            'business_start_date' => 'date',
            'business_income_level' => BusinessIncomeLevel::class,
            'business_latitude' => 'decimal:7',
            'business_longitude' => 'decimal:7',
            'residency_status' => ResidencyStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    public function identifications(): HasMany
    {
        return $this->hasMany(CustomerIdentification::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(CustomerBeneficiary::class);
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(CustomerFamilyMember::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Excludes id_number/id_photo_path/photo_path and the KYC-retention-purged
     * fields (tin/business_tin/religion/spouse cluster, see
     * PurgeExpiredKycData): logging them here would defeat kyc:purge-expired's
     * redaction (AD-16) by leaving a copy in the audit trail.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'branch_id',
                'customer_code',
                'first_name',
                'last_name',
                'phone',
                'gender',
                'next_of_kin_name',
                'next_of_kin_phone',
                'next_of_kin_relationship',
                'address',
                'status',
                'client_type',
                'external_id',
                'nationality',
                'email',
                'city_town',
                'state_region',
                'country',
                'digital_address',
                'business_name',
                'business_line',
                'business_structure',
                'business_start_date',
                'business_phone',
                'business_income_level',
                'business_address',
                'business_town',
                'business_county',
                'business_region',
                'marital_status',
                'has_past_loan',
                'occupation',
                'job_title',
                'other_names',
                'country_of_residence',
                'residence_permit',
                'residency_status',
                'assigned_agent_id',
            ])
            ->logOnlyDirty()
            ->useLogName('customer')
            ->dontSubmitEmptyLogs();
    }
}
