<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\SavingsAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SavingsAccount extends Model
{
    /** @use HasFactory<SavingsAccountFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'savings_product_id',
        'agent_id',
        'ledger_account_id',
        'account_number',
        'contribution_amount',
        'cycle_number',
        'cycle_started_at',
        'contributions_this_cycle',
        'balance',
        'target_amount',
        'matures_at',
        'matured_at',
        'interest_rate_bps',
        'share_count',
        'status',
        'opened_at',
        'closed_at',
        'client_reference',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contribution_amount' => 'integer',
            'cycle_number' => 'integer',
            'cycle_started_at' => 'date',
            'contributions_this_cycle' => 'integer',
            'balance' => 'integer',
            'target_amount' => 'integer',
            'matures_at' => 'date',
            'matured_at' => 'datetime',
            'interest_rate_bps' => 'integer',
            'share_count' => 'integer',
            'status' => AccountStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'savings_product_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    /** Null for non-target accounts; capped at 100 once the balance meets or exceeds the target. */
    public function targetProgressPercent(): ?float
    {
        if ($this->target_amount === null || $this->target_amount <= 0) {
            return null;
        }

        return min(100.0, round(($this->balance / $this->target_amount) * 100, 1));
    }

    /** Journal entries posted to this account's ledger account, oldest first. */
    public function entries(): HasManyThrough
    {
        return $this->hasManyThrough(
            JournalEntry::class,
            JournalLine::class,
            'ledger_account_id', // FK on journal_lines
            'id', // PK on journal_entries
            'ledger_account_id', // local key on savings_accounts
            'journal_entry_id', // FK on journal_lines pointing to journal_entries
        );
    }
}
