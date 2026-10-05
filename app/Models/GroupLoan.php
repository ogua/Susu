<?php

namespace App\Models;

use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanFrequency;
use Database\Factories\GroupLoanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One loan issued to a single member of a loan group. The member enters a
 * total principal, a refundable security deposit, and a periodic repayment
 * amount; ActivateGroupLoanAction spreads the principal into installments of
 * that periodic amount (remainder on the last). No product, no interest.
 */
class GroupLoan extends Model
{
    /** @use HasFactory<GroupLoanFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'loan_group_id',
        'loan_group_member_id',
        'customer_id',
        'agent_id',
        'activated_by',
        'receivable_account_id',
        'loan_number',
        'principal_amount',
        'security_deposit_amount',
        'periodic_amount',
        'outstanding_balance',
        'repayment_frequency',
        'start_date',
        'total_periods',
        'deposit_status',
        'status',
        'notes',
        'client_reference',
        'issued_at',
        'activated_at',
        'closed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'written_off_at',
        'write_off_reason',
        'write_off_amount',
        'write_off_savings_account_id',
        'write_off_savings_applied',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal_amount' => 'integer',
            'security_deposit_amount' => 'integer',
            'periodic_amount' => 'integer',
            'outstanding_balance' => 'integer',
            'repayment_frequency' => LoanFrequency::class,
            'start_date' => 'date',
            'total_periods' => 'integer',
            'deposit_status' => DepositStatus::class,
            'status' => GroupLoanStatus::class,
            'write_off_amount' => 'integer',
            'write_off_savings_applied' => 'integer',
            'issued_at' => 'datetime',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'closed_at' => 'datetime',
            'written_off_at' => 'datetime',
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

    public function loanGroupMember(): BelongsTo
    {
        return $this->belongsTo(LoanGroupMember::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'receivable_account_id');
    }

    public function writeOffSavingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'write_off_savings_account_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(GroupLoanInstallment::class)->orderBy('sequence');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(GroupLoanRepayment::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(GroupLoanDeposit::class);
    }

    /** Principal repaid so far — the receivable started at the full principal. */
    public function amountRepaid(): int
    {
        return max(0, $this->principal_amount - $this->outstanding_balance);
    }
}
