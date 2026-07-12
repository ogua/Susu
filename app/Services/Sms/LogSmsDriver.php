<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes the SMS to the log instead of a provider. */
class LogSmsDriver implements SmsDriver
{
    public function send(string $to, string $body, ?string $senderId = null): ?string
    {
        Log::info('SMS (log driver)', ['to' => $to, 'sender' => $senderId, 'body' => $body]);

        return null;
    }
}
