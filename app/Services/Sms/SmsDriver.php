<?php

namespace App\Services\Sms;

interface SmsDriver
{
    /**
     * Send one SMS. Returns the provider's message reference, or null when
     * the driver has none (e.g. the log driver).
     */
    public function send(string $to, string $body, ?string $senderId = null): ?string;
}
