<?php

namespace App\Models;

use Database\Factories\LoanGuarantorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone standing surety for a loan — optionally an existing customer
 * (customer_id). guaranteed_amount is in pesewas; id_number is encrypted.
 */
class LoanGuarantor extends Model
{
    /** @use HasFactory<LoanGuarantorFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'loan_id',
        'customer_id',
        'name',
        'phone',
        'relationship',
        'address',
        'id_type',
        'id_number',
        'guaranteed_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id_number' => 'encrypted',
            'guaranteed_amount' => 'integer',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
