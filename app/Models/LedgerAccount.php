<?php

namespace App\Models;

use App\Enums\LedgerAccountType;
use Database\Factories\LedgerAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LedgerAccount extends Model
{
    /** @use HasFactory<LedgerAccountFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'code',
        'name',
        'type',
        'accountable_type',
        'accountable_id',
        'balance',
        'currency',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerAccountType::class,
            'balance' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function accountable(): MorphTo
    {
        return $this->morphTo();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
