<?php

namespace App\Models;

use Database\Factories\GroupContributionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupContribution extends Model
{
    /** @use HasFactory<GroupContributionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'group_round_id',
        'group_member_id',
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

    public function round(): BelongsTo
    {
        return $this->belongsTo(GroupRound::class, 'group_round_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'group_member_id');
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
