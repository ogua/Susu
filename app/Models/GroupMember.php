<?php

namespace App\Models;

use Database\Factories\GroupMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupMember extends Model
{
    /** @use HasFactory<GroupMemberFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'group_id',
        'customer_id',
        'rotation_position',
        'status',
        'joined_at',
        'left_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rotation_position' => 'integer',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payoutRounds(): HasMany
    {
        return $this->hasMany(GroupRound::class, 'payout_member_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GroupContribution::class);
    }
}
