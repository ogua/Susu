<?php

namespace App\Models;

use App\Enums\AppPlatform;
use App\Services\AppUpdatePolicy;
use Database\Factories\AppVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client release (mobile store build or desktop installer) published by a
 * super admin. The newest active row per platform — by version_code, not the
 * version string — is what the apps' update check compares against.
 */
class AppVersion extends Model
{
    /** @use HasFactory<AppVersionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'platform',
        'version',
        'version_code',
        'minimum_supported_version',
        'is_forced_update',
        'is_active',
        'release_notes',
        'store_url',
        'released_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => AppPlatform::class,
            'version_code' => 'integer',
            'is_forced_update' => 'boolean',
            'is_active' => 'boolean',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $forget = fn (self $version) => AppUpdatePolicy::forget($version->platform);

        static::saved($forget);
        static::deleted($forget);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
