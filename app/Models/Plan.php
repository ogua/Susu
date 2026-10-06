<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A subscription plan tenants are billed on. A null limit means unlimited.
 * Prices are in minor units (pesewas), like every other amount in the app.
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /** Resources a plan can cap, mapped to their limit column. */
    public const LIMITS = [
        'branches' => 'max_branches',
        'staff' => 'max_staff',
        'customers' => 'max_customers',
    ];

    protected $fillable = [
        'name',
        'code',
        'description',
        'price_amount',
        'currency',
        'billing_period',
        'trial_days',
        'max_branches',
        'max_staff',
        'max_customers',
        'is_active',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'billing_period' => BillingPeriod::class,
            'trial_days' => 'integer',
            'max_branches' => 'integer',
            'max_staff' => 'integer',
            'max_customers' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }

    public function limitFor(string $resource): ?int
    {
        return $this->{self::LIMITS[$resource]};
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'price_amount', 'billing_period', 'trial_days', 'max_branches', 'max_staff', 'max_customers', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('plan')
            ->dontSubmitEmptyLogs();
    }
}
