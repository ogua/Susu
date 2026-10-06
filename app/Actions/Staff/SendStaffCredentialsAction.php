<?php

namespace App\Actions\Staff;

use App\Models\User;
use App\Notifications\StaffAccountCredentials;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Emails/texts a user their temporary sign-in details once the surrounding
 * transaction commits, so a rolled-back onboarding never sends credentials
 * for an account that doesn't exist. A delivery failure is reported, not
 * thrown — the account is still created and the admin can resend.
 */
class SendStaffCredentialsAction
{
    public function execute(User $user, #[\SensitiveParameter] string $temporaryPassword, string $reason = StaffAccountCredentials::REASON_WELCOME): void
    {
        DB::afterCommit(function () use ($user, $temporaryPassword, $reason): void {
            try {
                $user->notify(new StaffAccountCredentials($temporaryPassword, $reason));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
