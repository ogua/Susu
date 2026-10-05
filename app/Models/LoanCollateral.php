<?php

namespace App\Models;

use Database\Factories\LoanCollateralFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An asset pledged against a loan. estimated_value is in pesewas. */
class LoanCollateral extends Model
{
    /** @use HasFactory<LoanCollateralFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'loan_id',
        'type',
        'description',
        'estimated_value',
        'serial_number',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_value' => 'integer',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
