<?php

namespace App\Models;

use Database\Factories\CompanyPaymentSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's own Paystack account. When the secret key is set, its mobile
 * money collections settle into that account instead of the platform's.
 */
class CompanyPaymentSetting extends Model
{
    /** @use HasFactory<CompanyPaymentSettingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'paystack_public_key',
        'paystack_secret_key',
    ];

    protected $hidden = [
        'paystack_secret_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paystack_secret_key' => 'encrypted',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hasOwnPaystackAccount(): bool
    {
        return filled($this->paystack_secret_key);
    }
}
