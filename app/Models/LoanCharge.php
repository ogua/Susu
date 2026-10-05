<?php

namespace App\Models;

use Database\Factories\LoanChargeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One itemised upfront charge (processing fee, insurance, …). The loan's
 * origination_fee_amount is the sum of its charges and is deducted from the
 * cash disbursed, booked to fee income (see DisburseLoanAction).
 */
class LoanCharge extends Model
{
    /** @use HasFactory<LoanChargeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'loan_id',
        'name',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
