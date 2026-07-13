<?php

namespace App\Models;

use App\Enums\MobileMoneyProvider;
use App\Enums\PaymentFlow;
use App\Enums\PaymentIntentStatus;
use Database\Factories\PaymentIntentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentIntent extends Model
{
    /** @use HasFactory<PaymentIntentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'payable_type',
        'payable_id',
        'initiated_by',
        'flow',
        'channel',
        'phone',
        'amount',
        'status',
        'provider_reference',
        'client_reference',
        'journal_entry_id',
        'raw_response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'flow' => PaymentFlow::class,
            'channel' => MobileMoneyProvider::class,
            'status' => PaymentIntentStatus::class,
            'raw_response' => 'array',
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

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
