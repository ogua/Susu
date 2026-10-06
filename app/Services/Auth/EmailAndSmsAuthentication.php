<?php

namespace App\Services\Auth;

use App\Notifications\TwoFactorCode;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;

/**
 * Filament's email-code two-factor provider, with the code also texted to
 * the user's phone (TwoFactorCode sends to both). Users who would rather not
 * receive codes can use the authenticator app provider instead.
 */
class EmailAndSmsAuthentication extends EmailAuthentication
{
    protected int $codeExpiryMinutes = 10;

    protected string $codeNotification = TwoFactorCode::class;

    public function getLoginFormLabel(): string
    {
        return 'Code sent to your email and phone';
    }
}
