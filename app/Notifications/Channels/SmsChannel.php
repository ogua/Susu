<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Sms\SmsService;
use Illuminate\Notifications\Notification;

/**
 * Sends a notification's toSms() text to a user's phone. Uses the user's
 * company SMS connection when one is configured, otherwise the platform
 * sender — a company being onboarded has no provider of its own yet.
 */
class SmsChannel
{
    public function __construct(private readonly SmsService $sms) {}

    public function send(User $notifiable, Notification $notification): void
    {
        if (blank($notifiable->phone) || ! method_exists($notification, 'toSms')) {
            return;
        }

        $body = $notification->toSms($notifiable);
        $company = $notifiable->company;

        if ($company !== null && $this->sms->settingsFor($company)->provider !== 'log') {
            $this->sms->send($company, $notifiable->phone, $body);

            return;
        }

        $this->sms->sendSystem($notifiable->phone, $body);
    }
}
