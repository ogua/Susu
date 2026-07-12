<?php

namespace App\Models;

use App\Enums\ClientOrigin;
use App\Enums\EntryStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'reference',
        'client_reference',
        'origin',
        'type',
        'status',
        'payment_method',
        'description',
        'recorded_by',
        'recorded_at',
        'posted_at',
        'latitude',
        'longitude',
        'reversed_entry_id',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => ClientOrigin::class,
            'type' => TransactionType::class,
            'status' => EntryStatus::class,
            'payment_method' => PaymentMethod::class,
            'recorded_at' => 'datetime',
            'posted_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'meta' => 'array',
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }

    /** Total moved by this entry (sum of one side; both sides are equal). */
    public function amount(): int
    {
        return (int) $this->lines->sum('debit');
    }
}
