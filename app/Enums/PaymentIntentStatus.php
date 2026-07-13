<?php

namespace App\Enums;

enum PaymentIntentStatus: string
{
    case Initiated = 'initiated';

    /** Paystack is waiting for the customer to authorize on their phone (momo PIN prompt). */
    case PayOffline = 'pay_offline';

    /** Voucher networks (e.g. Telecel) require an OTP before the charge completes. */
    case SendOtp = 'send_otp';

    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Abandoned], true);
    }

    /**
     * Maps a raw Paystack `data.status` string onto our enum. Paystack's
     * charge API has a few other intermediate statuses (send_pin, send_phone,
     * send_birthday, open_url) for channels/flows this app doesn't use yet —
     * anything unrecognized falls back to Pending (still in progress, keep
     * polling) rather than throwing, since a webhook/verify race must never
     * crash on a status this mapping doesn't know about.
     */
    public static function fromProviderStatus(string $raw): self
    {
        return match ($raw) {
            'success' => self::Success,
            'failed', 'abandoned' => self::Failed,
            'pay_offline' => self::PayOffline,
            'send_otp' => self::SendOtp,
            default => self::Pending,
        };
    }
}
