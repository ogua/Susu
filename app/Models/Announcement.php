<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementLevel;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform-wide notice from the SusuApp team (maintenance windows, new
 * features, policy changes) shown to tenant staff in the admin panel and the
 * apps (GET /api/v1/announcements) between starts_at and ends_at.
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'title',
        'body',
        'level',
        'audience',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => AnnouncementLevel::class,
            'audience' => AnnouncementAudience::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Announcements running now.
     *
     * @param  Builder<Announcement>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('starts_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * Running announcements meant for this user's role, most severe first.
     *
     * @param  Builder<Announcement>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $audiences = collect(AnnouncementAudience::cases())
            ->filter(fn (AnnouncementAudience $audience): bool => $user->hasAnyRole($audience->roles()))
            ->map(fn (AnnouncementAudience $audience): string => $audience->value)
            ->values()
            ->all();

        $query->current()
            ->whereIn('audience', $audiences)
            ->orderByRaw("case level when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->latest('starts_at');
    }
}
