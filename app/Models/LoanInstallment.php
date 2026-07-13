<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use Database\Factories\LoanInstallmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInstallment extends Model
{
    /** @use HasFactory<LoanInstallmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'loan_id',
        'sequence',
        'due_date',
        'principal_due',
        'interest_due',
        'penalty_due',
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
            'principal_due' => 'integer',
            'interest_due' => 'integer',
            'penalty_due' => 'integer',
            'amount_paid' => 'integer',
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function totalDue(): int
    {
        return $this->principal_due + $this->interest_due + $this->penalty_due;
    }

    public function remaining(): int
    {
        return max(0, $this->totalDue() - $this->amount_paid);
    }
}
