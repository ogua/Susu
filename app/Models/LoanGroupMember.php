<?php

namespace App\Models;

use App\Enums\GroupLoanStatus;
use Database\Factories\LoanGroupMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoanGroupMember extends Model
{
    /** @use HasFactory<LoanGroupMemberFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'loan_group_id',
        'customer_id',
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
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function loanGroup(): BelongsTo
    {
        return $this->belongsTo(LoanGroup::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function groupLoans(): HasMany
    {
        return $this->hasMany(GroupLoan::class);
    }

    /** The member's current live loan, if any — there is at most one active loan per member. */
    public function activeLoan(): HasOne
    {
        return $this->hasOne(GroupLoan::class)->where('status', GroupLoanStatus::Active)->latestOfMany();
    }
}
