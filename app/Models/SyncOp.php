<?php

namespace App\Models;

use App\Enums\ClientOrigin;
use App\Enums\SyncOpType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncOp extends Model
{
    use HasUuids;

    protected $fillable = [
        'op_id',
        'company_id',
        'actor_id',
        'origin',
        'op_type',
        'status',
        'result',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => ClientOrigin::class,
            'op_type' => SyncOpType::class,
            'result' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
