<?php

namespace App\Models;

use App\Enums\DemoOrganisationType;
use Database\Factories\DemoRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A demo request from the public OguaFinance website. Platform-level (no
 * company); super admins work through them in the Demo Requests resource.
 */
class DemoRequest extends Model
{
    /** @use HasFactory<DemoRequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'organisation',
        'email',
        'phone',
        'organisation_type',
        'branches_count',
        'message',
        'ip_address',
        'handled_at',
        'handled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organisation_type' => DemoOrganisationType::class,
            'branches_count' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** @param  Builder<DemoRequest>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('handled_at');
    }

    public function markHandled(User $user): void
    {
        $this->update(['handled_at' => now(), 'handled_by' => $user->getKey()]);
    }
}
