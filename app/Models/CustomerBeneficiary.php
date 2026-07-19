<?php

namespace App\Models;

use Database\Factories\CustomerBeneficiaryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBeneficiary extends Model
{
    /** @use HasFactory<CustomerBeneficiaryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'customer_id',
        'name',
        'relationship',
        'amount_of_legacy',
        'phone',
        'address',
        'town',
        'county',
        'state_region',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_of_legacy' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
