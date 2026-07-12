<?php

namespace App\Models;

use App\Enums\AgentSummaryStatus;
use Database\Factories\AgentDailySummaryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDailySummary extends Model
{
    /** @use HasFactory<AgentDailySummaryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'agent_id',
        'summary_date',
        'collections_total',
        'collections_count',
        'expected_cash',
        'declared_cash',
        'variance',
        'status',
        'reconciled_by',
        'notes',
        'client_reference',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collections_total' => 'integer',
            'collections_count' => 'integer',
            'expected_cash' => 'integer',
            'declared_cash' => 'integer',
            'variance' => 'integer',
            'status' => AgentSummaryStatus::class,
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

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
