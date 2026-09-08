<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use Database\Factories\GroupLoanInstallmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupLoanInstallment extends Model
{
    /** @use HasFactory<GroupLoanInstallmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'group_loan_id',
        'sequence',
        'due_date',
        'amount_due',
        'amount_paid',
        'status',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'date',
            'amount_due' => 'integer',
            'amount_paid' => 'integer',
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function groupLoan(): BelongsTo
    {
        return $this->belongsTo(GroupLoan::class);
    }

    public function remaining(): int
    {
        return max(0, $this->amount_due - $this->amount_paid);
    }
}
