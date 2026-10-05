<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Arkesel SMS v2 (POST /api/v2/sms/send). Each customer notification is one
 * message to one recipient, so the plain send endpoint is used rather than
 * the template endpoint. Throws on any non-success so the caller can mark
 * the notification as failed.
 */
class ArkeselSmsDriver implements SmsDriver
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://sms.arkesel.com',
    ) {}

    public function send(string $to, string $body, ?string $senderId = null): ?string
    {
        if ($this->apiKey === '' || blank($senderId)) {
            throw new RuntimeException('Arkesel needs both an API key and a sender ID.');
        }

        $recipient = self::normalizePhone($to);
        if ($recipient === null) {
            throw new RuntimeException("Invalid phone number for SMS: {$to}");
        }

        $response = Http::withHeaders(['api-key' => $this->apiKey])
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->timeout(20)
            ->retry(3, 200, throw: false)
            ->post('/api/v2/sms/send', [
                'sender' => $senderId,
                'message' => $body,
                'recipients' => [$recipient],
            ]);

        $data = $response->json() ?? [];

        if (! $response->successful() || ($data['status'] ?? null) !== 'success') {
            throw new RuntimeException('Arkesel SMS failed: '.($data['message'] ?? $response->body()));
        }

        return $data['data'][0]['id'] ?? null;
    }

    /** Ghana numbers in Arkesel's 233XXXXXXXXX format; null when not a valid number. */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '233'.substr($digits, 1);
        }

        return preg_match('/^233\d{9}$/', $digits) ? $digits : null;
    }
}
