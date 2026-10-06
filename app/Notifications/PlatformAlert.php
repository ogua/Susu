<?php

namespace App\Notifications;

use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Something on the platform failed and won't wait for the morning digest
 * (billing run crashed, a data export failed). Email + super admin bell.
 */
class PlatformAlert extends BaseNotification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(config('app.name').' alert: '.$this->title)
            ->line($this->body)
            ->action('Open the super admin panel', url('/super-admin'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->danger()
            ->getDatabaseMessage();
    }

    /** Sends to every active super admin. */
    public static function toSuperAdmins(string $title, string $body): void
    {
        Notification::send(
            User::role('super_admin')->where('is_active', true)->get(),
            new self($title, $body),
        );
    }
}
