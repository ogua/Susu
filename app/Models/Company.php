<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Company extends Model
{
    /** suspended_reason set by the billing run; payment lifts only this kind of suspension. */
    public const SUSPENDED_FOR_NON_PAYMENT = 'non_payment';

    /** suspended_reason set when a super admin suspends by hand. */
    public const SUSPENDED_BY_OPERATOR = 'operator';

    /** suspended_reason set when the company is archived (offboarded). */
    public const SUSPENDED_ARCHIVED = 'archived';

    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'logo',
        'name',
        'description',
        'slug',
        'domain_alias',
        'website',
        'primary_color',
        'secondary_color',
        'address',
        'contact_email',
        'contact_phone',
        'is_active',
        'suspended_reason',
        'archived_at',
        'personal_data_erased_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
            'personal_data_erased_at' => 'datetime',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The company super admins who can currently sign in and manage staff. */
    public function activeCompanyAdmins(): HasMany
    {
        return $this->users()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'company_admin'));
    }

    public function savingsProducts(): HasMany
    {
        return $this->hasMany(SavingsProduct::class);
    }

    public function loanProducts(): HasMany
    {
        return $this->hasMany(LoanProduct::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(CompanySubscription::class);
    }

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(CompanyExport::class);
    }

    public function smsSetting(): HasOne
    {
        return $this->hasOne(CompanySmsSetting::class);
    }

    public function paymentSetting(): HasOne
    {
        return $this->hasOne(CompanyPaymentSetting::class);
    }

    /**
     * Companies nobody can use yet: no branch to log in through, or no
     * active company admin to sign in and add staff.
     *
     * @param  Builder<Company>  $query
     */
    #[Scope]
    protected function onboardingIncomplete(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereDoesntHave('branches')
            ->orWhereDoesntHave('activeCompanyAdmins'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'domain_alias', 'contact_email', 'contact_phone', 'is_active', 'suspended_reason', 'archived_at', 'personal_data_erased_at'])
            ->logOnlyDirty()
            ->useLogName('company')
            ->dontSubmitEmptyLogs();
    }
}
