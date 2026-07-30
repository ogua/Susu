<?php

namespace App\Models;

use App\Enums\GroupLoanStatus;
use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use Database\Factories\GroupLoanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GroupLoan extends Model
{
    /** @use HasFactory<GroupLoanFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'loan_group_id',
        'loan_product_id',
        'agent_id',
        'approved_by',
        'receivable_account_id',
        'loan_number',
        'principal_amount',
        'interest_method',
        'interest_rate_bps',
        'term_period_count',
        'repayment_frequency',
        'origination_fee_amount',
        'penalty_rate_bps',
        'grace_period_days',
        'total_interest',
        'total_repayable',
        'outstanding_balance',
        'member_count_at_disbursement',
        'status',
        'rejection_reason',
        'notes',
        'client_reference',
        'applied_at',
        'approved_at',
        'disbursed_at',
        'closed_at',
        'written_off_at',
        'write_off_reason',
        'write_off_amount',
        'previous_group_loan_id',
        'rolled_over_amount',
        'refinanced_at',
        'refinance_type',
        'refinance_reason',
        'refinance_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal_amount' => 'integer',
            'interest_method' => InterestMethod::class,
            'interest_rate_bps' => 'integer',
            'term_period_count' => 'integer',
            'repayment_frequency' => LoanFrequency::class,
            'origination_fee_amount' => 'integer',
            'penalty_rate_bps' => 'integer',
            'grace_period_days' => 'integer',
            'total_interest' => 'integer',
            'total_repayable' => 'integer',
            'outstanding_balance' => 'integer',
            'member_count_at_disbursement' => 'integer',
            'status' => GroupLoanStatus::class,
            'applied_at' => 'datetime',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'closed_at' => 'datetime',
            'written_off_at' => 'datetime',
            'write_off_amount' => 'integer',
            'rolled_over_amount' => 'integer',
            'refinanced_at' => 'datetime',
            'refinance_amount' => 'integer',
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

    public function loanGroup(): BelongsTo
    {
        return $this->belongsTo(LoanGroup::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'receivable_account_id');
    }

    public function borrowers(): HasMany
    {
        return $this->hasMany(GroupLoanBorrower::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(GroupLoanInstallment::class)->orderBy('sequence');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(GroupLoanRepayment::class);
    }

    public function previousGroupLoan(): BelongsTo
    {
        return $this->belongsTo(GroupLoan::class, 'previous_group_loan_id');
    }

    public function nextGroupLoan(): HasOne
    {
        return $this->hasOne(GroupLoan::class, 'previous_group_loan_id');
    }
}
