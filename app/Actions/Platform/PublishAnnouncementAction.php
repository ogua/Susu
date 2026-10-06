<?php

namespace App\Actions\Platform;

use App\Models\Announcement;
use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Creates an announcement and, when asked, also drops it in the bell
 * (database notification) of every active user in its audience whose
 * company is active. The banner in the admin panel and the apps' feed
 * (GET /api/v1/announcements) pick it up either way.
 */
class PublishAnnouncementAction
{
    /**
     * @param  array{title: string, body: string, level: mixed, audience: mixed, starts_at: mixed, ends_at?: mixed}  $data
     */
    public function execute(array $data, ?User $author, bool $notifyNow = false): Announcement
    {
        $announcement = Announcement::create([...$data, 'created_by' => $author?->id]);

        if ($notifyNow) {
            $recipients = User::query()
                ->where('is_active', true)
                ->whereHas('company', fn ($company) => $company->where('is_active', true))
                ->role($announcement->audience->roles())
                ->get();

            Notification::make()
                ->title($announcement->title)
                ->body($announcement->body)
                ->status(match ($announcement->level->value) {
                    'critical' => 'danger',
                    'warning' => 'warning',
                    default => 'info',
                })
                ->sendToDatabase($recipients);
        }

        return $announcement;
    }
}
