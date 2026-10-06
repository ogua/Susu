<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use Database\Factories\CompanySubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A company's one current subscription; its invoices are the billing history. */
class CompanySubscription extends Model
{
    /** @use HasFactory<CompanySubscriptionFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function unpaidInvoices(): HasMany
    {
        return $this->invoices()->where('status', InvoiceStatus::Unpaid);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['plan_id', 'status', 'trial_ends_at', 'current_period_end', 'cancelled_at'])
            ->logOnlyDirty()
            ->useLogName('subscription')
            ->dontSubmitEmptyLogs();
    }
}
