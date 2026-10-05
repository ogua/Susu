<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanyPaymentSetting;
use App\Models\CompanySmsSetting;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A company's SMS (Arkesel) and Paystack connection, shared by the web
 * Integrations page and PUT /api/v1/company/integrations. Secret fields left
 * blank keep the stored value, so a form never has to echo a secret back.
 */
class UpdateCompanyIntegrationSettingsAction
{
    public function __construct(private SmsService $sms) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'sms_provider' => ['sometimes', 'required', Rule::in(['log', 'arkesel'])],
            'sms_sender_id' => ['sometimes', 'nullable', 'string', 'max:11'],
            'sms_api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sms_notifications_enabled' => ['sometimes', 'boolean'],
            'sms_quiet_hours_start' => ['sometimes', 'required', 'date_format:H:i'],
            'sms_quiet_hours_end' => ['sometimes', 'required', 'date_format:H:i'],
            'paystack_public_key' => ['sometimes', 'nullable', 'string', 'starts_with:pk_', 'max:255'],
            'paystack_secret_key' => ['sometimes', 'nullable', 'string', 'starts_with:sk_', 'max:255'],
            'disconnect_paystack' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array{sms_provider?: string, sms_sender_id?: ?string, sms_api_key?: ?string, sms_notifications_enabled?: bool, sms_quiet_hours_start?: string, sms_quiet_hours_end?: string, paystack_public_key?: ?string, paystack_secret_key?: ?string, disconnect_paystack?: bool}  $data
     */
    public function execute(Company $company, array $data): Company
    {
        return DB::transaction(function () use ($company, $data): Company {
            $this->updateSms($this->sms->settingsFor($company), $data);
            $this->updatePayments($company, $data);

            return $company->load(['smsSetting', 'paymentSetting']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateSms(CompanySmsSetting $settings, array $data): void
    {
        $fields = [
            'sms_provider' => 'provider',
            'sms_sender_id' => 'sender_id',
            'sms_notifications_enabled' => 'notifications_enabled',
            'sms_quiet_hours_start' => 'quiet_hours_start',
            'sms_quiet_hours_end' => 'quiet_hours_end',
        ];

        foreach ($fields as $input => $column) {
            if (array_key_exists($input, $data)) {
                $settings->{$column} = $data[$input];
            }
        }

        if (filled($data['sms_api_key'] ?? null)) {
            $settings->api_key = $data['sms_api_key'];
        }

        if ($settings->provider === 'arkesel' && (blank($settings->sender_id) || blank($settings->api_key))) {
            throw ValidationException::withMessages([
                'sms_provider' => 'Arkesel needs both a sender ID and an API key.',
            ]);
        }

        $settings->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updatePayments(Company $company, array $data): void
    {
        $settings = CompanyPaymentSetting::firstOrNew(['company_id' => $company->id]);

        if ($data['disconnect_paystack'] ?? false) {
            $settings->paystack_public_key = null;
            $settings->paystack_secret_key = null;
        } else {
            if (array_key_exists('paystack_public_key', $data)) {
                $settings->paystack_public_key = $data['paystack_public_key'];
            }

            if (filled($data['paystack_secret_key'] ?? null)) {
                $settings->paystack_secret_key = $data['paystack_secret_key'];
            }
        }

        $settings->save();
    }
}
