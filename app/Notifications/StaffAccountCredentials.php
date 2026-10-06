<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sign-in details for a new staff account, or a password reset by an
 * administrator. The password is temporary: the user must replace it at
 * first sign-in (users.must_change_password), on the web or in the apps.
 *
 * Sent synchronously on purpose: a queued copy would sit in the jobs table
 * with the temporary password in it.
 */
class StaffAccountCredentials extends Notification
{
    use Queueable;

    public const REASON_WELCOME = 'welcome';

    public const REASON_RESET = 'reset';

    public function __construct(
        #[\SensitiveParameter] public readonly string $temporaryPassword,
        public readonly string $reason = self::REASON_WELCOME,
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
        $companyName = $notifiable->company?->name ?? config('app.name');

        $message = (new MailMessage)
            ->subject($this->reason === self::REASON_WELCOME
                ? "Your {$companyName} account is ready"
                : "Your {$companyName} password has been reset")
            ->greeting("Hello {$notifiable->name},");

        $message = $this->reason === self::REASON_WELCOME
            ? $message->line("An account has been created for you on {$companyName}.")
            : $message->line('An administrator has reset your password.');

        return $message
            ->line("Sign in with: {$notifiable->email}")
            ->line("Temporary password: {$this->temporaryPassword}")
            ->line('You will be asked to choose your own password when you sign in.')
            ->action('Sign in', $this->loginUrl())
            ->line('If you did not expect this message, contact your administrator.');
    }

    public function toSms(User $notifiable): string
    {
        $companyName = $notifiable->company?->name ?? config('app.name');
        $opening = $this->reason === self::REASON_WELCOME
            ? "Welcome to {$companyName}."
            : "{$companyName}: your password was reset.";

        return "{$opening} Sign in at {$this->loginUrl()} with {$notifiable->email} and temporary password {$this->temporaryPassword}. You will be asked to change it.";
    }

    private function loginUrl(): string
    {
        return Filament::getPanel('admin')->getLoginUrl();
    }
}
