<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The daily platform health email to super admins (see BuildPlatformDigestAction). */
class PlatformDigest extends Notification
{
    use Queueable;

    /**
     * @param  array{items: list<array{label: string, value: string, alert: bool}>, alerts: int}  $digest
     */
    public function __construct(public readonly array $digest) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $alerts = $this->digest['alerts'];
        $message = (new MailMessage)
            ->subject(config('app.name').' daily digest — '.($alerts > 0 ? "{$alerts} item(s) need attention" : 'all clear'))
            ->line($alerts > 0 ? 'These need attention (marked ⚠):' : 'Nothing needs attention today.');

        foreach ($this->digest['items'] as $item) {
            $message->line(($item['alert'] ? '⚠ ' : '• ').$item['label'].': '.$item['value']);
        }

        return $message->action('Open the super admin panel', url('/super-admin'));
    }
}
