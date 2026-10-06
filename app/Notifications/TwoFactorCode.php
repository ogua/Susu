<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * A sign-in code for two-factor authentication, sent to both the user's
 * email and phone so either works. Sent synchronously: the code expires in
 * minutes and must not wait in (or be stored by) the queue.
 */
class TwoFactorCode extends Notification
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter]
        public string $code,
        public int $codeExpiryMinutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return array_values(array_filter([
            filled($notifiable->email) ? 'mail' : null,
            filled($notifiable->phone) ? SmsChannel::class : null,
        ]));
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' sign-in code')
            ->line("Your sign-in code is: {$this->code}")
            ->line("It expires in {$this->codeExpiryMinutes} minutes. If you did not try to sign in, change your password.");
    }

    public function toSms(User $notifiable): string
    {
        return config('app.name')." sign-in code: {$this->code}. Expires in {$this->codeExpiryMinutes} min. Never share it.";
    }
}
