<?php

namespace App\Notifications;

use App\Models\DemoRequest;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A new demo request came in from the public website. Email + super admin bell. */
class DemoRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly DemoRequest $demoRequest) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->demoRequest;

        return (new MailMessage)
            ->subject('New demo request: '.$request->organisation)
            ->replyTo($request->email, $request->name)
            ->line("{$request->name} from {$request->organisation} ({$request->organisation_type->getLabel()}) asked for a demo.")
            ->line("Email: {$request->email} · Phone: {$request->phone}")
            ->when($request->branches_count, fn (MailMessage $mail) => $mail->line("Branches: {$request->branches_count}"))
            ->when($request->message, fn (MailMessage $mail) => $mail->line('Message: '.$request->message))
            ->action('Open demo requests', url('/super-admin/demo-requests'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('New demo request')
            ->body("{$this->demoRequest->name} · {$this->demoRequest->organisation}")
            ->info()
            ->getDatabaseMessage();
    }
}
