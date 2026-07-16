<?php

namespace App\Models;

use App\Enums\LicenseSaleStatus;
use Database\Factories\DesktopLicenseSaleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesktopLicenseSale extends Model
{
    /** @use HasFactory<DesktopLicenseSaleFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'install_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'duration_days',
        'amount',
        'currency',
        'status',
        'provider_reference',
        'license_key',
        'expires_at',
        'issued_by',
        'source',
        'notified_at',
        'raw_response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'amount' => 'integer',
            'status' => LicenseSaleStatus::class,
            'expires_at' => 'date',
            'notified_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
