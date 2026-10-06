<?php

namespace App\Actions\Staff;

use App\Models\User;
use App\Notifications\StaffAccountCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administrator password reset: sets a new temporary password, signs the
 * user out of the apps, flags the account to choose its own password at the
 * next sign-in, and sends the details by email and SMS.
 */
class IssueTemporaryPasswordAction
{
    public function __construct(private readonly SendStaffCredentialsAction $sendCredentials) {}

    public static function generatePassword(): string
    {
        return Str::password(10, symbols: false);
    }

    public function execute(User $user): string
    {
        $password = self::generatePassword();

        DB::transaction(function () use ($user, $password): void {
            $user->update([
                'password' => $password,
                'must_change_password' => true,
            ]);

            $user->tokens()->delete();

            $this->sendCredentials->execute($user, $password, StaffAccountCredentials::REASON_RESET);
        });

        return $password;
    }
}
