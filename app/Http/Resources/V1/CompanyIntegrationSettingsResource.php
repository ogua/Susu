<?php

namespace App\Http\Resources\V1;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Secrets never leave the server: only whether each is set is returned.
 *
 * @mixin Company
 */
class CompanyIntegrationSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sms = $this->smsSetting;
        $payments = $this->paymentSetting;

        return [
            'sms' => [
                'provider' => $sms?->provider ?? 'log',
                'sender_id' => $sms?->sender_id,
                'api_key_set' => filled($sms?->api_key),
                'notifications_enabled' => $sms?->notifications_enabled ?? true,
                'quiet_hours_start' => substr((string) ($sms?->quiet_hours_start ?? '21:00'), 0, 5),
                'quiet_hours_end' => substr((string) ($sms?->quiet_hours_end ?? '07:00'), 0, 5),
            ],
            'paystack' => [
                'uses_own_account' => (bool) $payments?->hasOwnPaystackAccount(),
                'public_key' => $payments?->paystack_public_key,
                'secret_key_set' => filled($payments?->paystack_secret_key),
                'webhook_url' => route('webhooks.paystack.company', $this->resource),
            ],
        ];
    }
}
