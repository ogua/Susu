<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Webhook Gateway
    |--------------------------------------------------------------------------
    |
    | oguapaymentwebhook is the single URL Paystack is configured to call for
    | every project sharing this Paystack account. It forwards events for
    | this project (susu references prefixed "SUSU-", license references
    | prefixed "SUSULIC-") to the internal endpoints below, signing each
    | forward with this secret so the endpoint can tell a real forward from
    | an arbitrary caller. Must match the SUSU_WEBHOOK_FORWARD_SECRET /
    | SUSULIC_WEBHOOK_FORWARD_SECRET configured on the gateway.
    |
    */
    'webhook_gateway' => [
        'forward_secret' => env('WEBHOOK_GATEWAY_FORWARD_SECRET'),
    ],

    /*
     * Platform-level SMS (no Company context), used for scenarios like
     * license-key delivery where the recipient isn't a company's customer.
     * Separate from company_sms_settings (App\Services\Sms\SmsService::send()),
     * which requires a Company.
     */
    'platform_sms' => [
        'provider' => env('PLATFORM_SMS_PROVIDER', 'log'),
        'sender_id' => env('PLATFORM_SMS_SENDER_ID'),
        'api_key' => env('PLATFORM_SMS_API_KEY'),
    ],

    /*
     * Arkesel host. Companies bring their own API key and sender ID
     * (company_sms_settings); only the host is shared, so a white-label
     * Arkesel endpoint can be swapped in here.
     */
    'arkesel' => [
        'base_url' => env('ARKESEL_BASE_URL', 'https://sms.oguaschoolz.com'),
    ],

];
