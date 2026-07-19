<?php

namespace App\Models;

use Database\Factories\GroupLoanBorrowerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupLoanBorrower extends Model
{
    /** @use HasFactory<GroupLoanBorrowerFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'group_loan_id',
        'loan_group_member_id',
        'customer_id',
        'share_principal',
        'share_outstanding',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'share_principal' => 'integer',
            'share_outstanding' => 'integer',
        ];
    }

    public function groupLoan(): BelongsTo
    {
        return $this->belongsTo(GroupLoan::class);
    }

    public function loanGroupMember(): BelongsTo
    {
        return $this->belongsTo(LoanGroupMember::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(GroupLoanRepayment::class);
    }
}
