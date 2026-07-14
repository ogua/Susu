<?php

namespace App\Models;

use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use Database\Factories\LoanProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LoanProduct extends Model
{
    /** @use HasFactory<LoanProductFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'interest_method',
        'interest_rate_bps',
        'term_period_count',
        'repayment_frequency',
        'origination_fee_amount',
        'penalty_rate_bps',
        'grace_period_days',
        'min_amount',
        'max_amount',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interest_method' => InterestMethod::class,
            'interest_rate_bps' => 'integer',
            'term_period_count' => 'integer',
            'repayment_frequency' => LoanFrequency::class,
            'origination_fee_amount' => 'integer',
            'penalty_rate_bps' => 'integer',
            'grace_period_days' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('loan_product')
            ->dontSubmitEmptyLogs();
    }
}
