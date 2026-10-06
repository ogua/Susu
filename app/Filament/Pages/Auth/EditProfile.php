<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Staff\UpdateProfilePhotoAction;
use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;

/**
 * Adds the profile photo (shown on the agent tracking map and in the mobile
 * app) to Filament's built-in profile page. It is also where a user on a
 * temporary password is sent (RequirePasswordChange): a new password is
 * required for them, and saving one clears users.must_change_password.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('photo_path')
                    ->label('Photo')
                    ->image()
                    ->avatar()
                    ->imageEditor()
                    ->disk('public')
                    ->directory(UpdateProfilePhotoAction::DIRECTORY)
                    ->visibility('public'),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent()
                    ->required(fn (): bool => $this->mustChangePassword()),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (filled($data['password'] ?? null)) {
            $data['must_change_password'] = false;
        }

        return $data;
    }

    private function mustChangePassword(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->must_change_password;
    }
}
