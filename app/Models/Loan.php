<?php

namespace App\Models;

use App\Enums\InterestMethod;
use App\Enums\LoanFrequency;
use App\Enums\LoanStatus;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'loan_product_id',
        'savings_account_id',
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
        'status',
        'guarantor_name',
        'guarantor_phone',
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
        'previous_loan_id',
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
            'status' => LoanStatus::class,
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
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

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function previousLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'previous_loan_id');
    }

    public function nextLoan(): HasOne
    {
        return $this->hasOne(Loan::class, 'previous_loan_id');
    }
}
