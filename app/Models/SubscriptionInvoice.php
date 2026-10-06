<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\SubscriptionInvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    /** @use HasFactory<SubscriptionInvoiceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'company_subscription_id',
        'plan_id',
        'number',
        'amount',
        'currency',
        'period_start',
        'period_end',
        'due_at',
        'status',
        'paid_at',
        'reminder_sent_at',
        'overdue_notice_sent_at',
        'payment_method',
        'payment_reference',
        'provider_reference',
        'recorded_by',
        'raw_response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_at' => 'datetime',
            'status' => InvoiceStatus::class,
            'paid_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'overdue_notice_sent_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Unpaid && $this->due_at->isPast();
    }
}
