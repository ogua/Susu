<?php

namespace App\Models;

use Database\Factories\GroupLoanDepositFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of a member's security deposit being paid into one of their
 * savings accounts. `type` is `held` going forward; `applied`/`refunded`/
 * `seized` are legacy values from the pre-savings-account escrow model and
 * may still appear on historical rows.
 */
class GroupLoanDeposit extends Model
{
    /** @use HasFactory<GroupLoanDepositFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'group_loan_id',
        'savings_account_id',
        'journal_entry_id',
        'recorded_by',
        'amount',
        'type',
        'recorded_at',
        'client_reference',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function groupLoan(): BelongsTo
    {
        return $this->belongsTo(GroupLoan::class);
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
