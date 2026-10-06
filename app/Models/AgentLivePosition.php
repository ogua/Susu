<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLivePosition extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'agent_id',
        'latitude',
        'longitude',
        'on_duty',
        'located_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'on_duty' => 'boolean',
            'located_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Only field agents are tracked; managers and admins who also use the
     * agent endpoints are left off the map.
     *
     * @param  Builder<AgentLivePosition>  $query
     */
    #[Scope]
    protected function fieldAgents(Builder $query): void
    {
        $query->whereHas('agent', fn (Builder $agent) => $agent->role('field_agent'));
    }
}
