<?php

namespace App\Actions\Staff;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * A signed-in user replacing their own password — the forced first-login
 * change and any later change from the apps. Shared by the web profile page
 * (via ClearsPasswordChangeRequirement) and PUT /api/v1/auth/password.
 */
class ChangeOwnPasswordAction
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), 'different:current_password'],
        ];
    }

    public function execute(User $user, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): User
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->update([
            'password' => $newPassword,
            'must_change_password' => false,
        ]);

        return $user;
    }
}
