<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\SavingsProductType;
use Database\Factories\SavingsProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SavingsProduct extends Model
{
    /** @use HasFactory<SavingsProductFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'type',
        'contribution_amount',
        'cycle_length_days',
        'commission_type',
        'commission_value',
        'early_withdrawal_penalty_bps',
        'interest_rate_bps',
        'par_value',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SavingsProductType::class,
            'contribution_amount' => 'integer',
            'cycle_length_days' => 'integer',
            'commission_type' => CommissionType::class,
            'commission_value' => 'integer',
            'early_withdrawal_penalty_bps' => 'integer',
            'interest_rate_bps' => 'integer',
            'par_value' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('savings_product')
            ->dontSubmitEmptyLogs();
    }
}
