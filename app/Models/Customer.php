<?php

namespace App\Models;

use App\Enums\AccountStatus;
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

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Excludes id_number/id_photo_path/photo_path: logging them here would
     * defeat kyc:purge-expired's redaction (AD-16) by leaving a copy in the
     * audit trail.
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
            ])
            ->logOnlyDirty()
            ->useLogName('customer')
            ->dontSubmitEmptyLogs();
    }
}
