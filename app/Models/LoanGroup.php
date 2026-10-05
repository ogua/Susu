<?php

namespace App\Models;

use App\Enums\GroupLoanStatus;
use Database\Factories\LoanGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LoanGroup extends Model
{
    /** @use HasFactory<LoanGroupFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'created_by',
        'name',
        'code',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LoanGroupMember::class)->orderBy('joined_at');
    }

    public function groupLoans(): HasMany
    {
        return $this->hasMany(GroupLoan::class);
    }

    public function activeGroupLoans(): HasMany
    {
        return $this->hasMany(GroupLoan::class)->where('status', GroupLoanStatus::Active);
    }

    /** Sum of every active member loan's outstanding balance — the group's total debt. */
    public function outstandingBalance(): int
    {
        return (int) $this->activeGroupLoans()->sum('outstanding_balance');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('loan_group')
            ->dontSubmitEmptyLogs();
    }
}
