<?php

namespace App\Actions\Staff;

use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Replaces (or removes, when given null) a user's own profile photo. Photos
 * live on the public disk because they are shown on the agent tracking map
 * and in the mobile app. The User model deletes the previous file.
 */
class UpdateProfilePhotoAction
{
    public const DIRECTORY = 'staff/photos';

    public function execute(User $user, ?UploadedFile $photo): User
    {
        $user->update([
            'photo_path' => $photo?->store(self::DIRECTORY, 'public'),
        ]);

        return $user;
    }
}
