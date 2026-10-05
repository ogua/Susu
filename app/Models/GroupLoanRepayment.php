<?php

namespace App\Models;

use App\Enums\EntryStatus;
use Database\Factories\GroupLoanRepaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Repayments whose journal entry still stands (not reversed).
     *
     * @param  Builder<GroupLoanRepayment>  $query
     */
    #[Scope]
    protected function notReversed(Builder $query): void
    {
        $query->whereHas('journalEntry', fn (Builder $entry) => $entry->where('status', '!=', EntryStatus::Reversed));
    }
}
