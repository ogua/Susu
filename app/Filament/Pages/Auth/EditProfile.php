<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Staff\UpdateProfilePhotoAction;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;

/**
 * Adds the profile photo (shown on the agent tracking map and in the mobile
 * app) to Filament's built-in profile page.
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
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }
}
