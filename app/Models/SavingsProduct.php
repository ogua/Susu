<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\SavingsProductType;
use Database\Factories\SavingsProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavingsProduct extends Model
{
    /** @use HasFactory<SavingsProductFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'type',
        'contribution_amount',
        'cycle_length_days',
        'commission_type',
        'commission_value',
        'early_withdrawal_penalty_bps',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SavingsProductType::class,
            'contribution_amount' => 'integer',
            'cycle_length_days' => 'integer',
            'commission_type' => CommissionType::class,
            'commission_value' => 'integer',
            'early_withdrawal_penalty_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }
}
