<?php

namespace App\Models;

use App\Enums\IdentificationType;
use Database\Factories\CustomerIdentificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerIdentification extends Model
{
    /** @use HasFactory<CustomerIdentificationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'customer_id',
        'id_type',
        'id_number',
        'issue_date',
        'expiry_date',
        'description',
        'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id_type' => IdentificationType::class,
            'id_number' => 'encrypted',
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'is_primary' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
