<?php

namespace App\Jobs\Ussd;

use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;
use App\Models\UssdPaymentReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Tells the Ogua USSD platform how a USSD-started MoMo charge finished, signed with the
 * shared USSD secret: X-Ussd-Signature = HMAC-SHA256("{timestamp}.{nonce}.{body}").
 * Retried with backoff; the platform ignores repeats, so a retry is always safe.
 */
class ReportUssdTransactionStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $clientReference) {}

    public function handle(): void
    {
        $report = UssdPaymentReport::query()->find($this->clientReference);
        $intent = PaymentIntent::query()->where('client_reference', $this->clientReference)->first();
        $platformUrl = config('services.ussd.platform_url');
        $secret = (string) config('services.ussd.secret');

        if ($report === null || $report->reported_at !== null || $intent === null || ! $intent->status->isTerminal()) {
            return;
        }

        if (blank($platformUrl) || $secret === '') {
            return;
        }

        $status = $intent->status === PaymentIntentStatus::Success ? 'completed' : 'failed';
        $body = json_encode(['status' => $status, 'reference' => $intent->provider_reference]);
        $timestamp = (string) now()->getTimestamp();
        $nonce = Str::random(32);

        Http::timeout(10)
            ->acceptJson()
            ->withHeaders([
                'X-Ussd-Timestamp' => $timestamp,
                'X-Ussd-Nonce' => $nonce,
                'X-Ussd-Signature' => hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $secret),
            ])
            ->withBody($body, 'application/json')
            ->post(rtrim((string) $platformUrl, '/')."/api/ussd/products/susu/transactions/{$this->clientReference}")
            ->throw();

        $report->forceFill(['reported_status' => $status, 'reported_at' => now()])->save();
    }
}
