<?php

namespace App\Models;

use Database\Factories\GroupLoanRepaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupLoanRepayment extends Model
{
    /** @use HasFactory<GroupLoanRepaymentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'group_loan_id',
        'group_loan_borrower_id',
        'journal_entry_id',
        'recorded_by',
        'amount',
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

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(GroupLoanBorrower::class, 'group_loan_borrower_id');
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
