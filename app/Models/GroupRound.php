<?php

namespace App\Models;

use App\Enums\GroupRoundStatus;
use Database\Factories\GroupRoundFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupRound extends Model
{
    /** @use HasFactory<GroupRoundFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'group_id',
        'payout_member_id',
        'payout_entry_id',
        'round_number',
        'due_date',
        'total_expected',
        'total_collected',
        'status',
        'paid_out_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'due_date' => 'date',
            'total_expected' => 'integer',
            'total_collected' => 'integer',
            'status' => GroupRoundStatus::class,
            'paid_out_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function payoutMember(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'payout_member_id');
    }

    public function payoutEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payout_entry_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GroupContribution::class);
    }

    public function remaining(): int
    {
        return max(0, $this->total_expected - $this->total_collected);
    }
}
