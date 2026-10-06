<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Schemas;

use App\Models\User;
use Filament\Forms\Components\TextInput;

/**
 * Login details for a company super admin — shared by the onboarding
 * wizard's last step and the company page's "Add company admin" action.
 */
class CompanyAdminForm
{
    /**
     * @return array<int, mixed>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->unique(table: User::class, column: 'email', ignoreRecord: false)
                ->helperText('They sign in to the admin panel with this email; their sign-in details are sent here.'),
            TextInput::make('phone')
                ->tel()
                ->maxLength(32)
                ->helperText('Sign-in details are also sent by SMS when a phone is given.'),
            TextInput::make('password')
                ->label('Temporary password')
                ->password()
                ->revealable()
                ->minLength(8)
                ->confirmed()
                ->helperText('Leave blank to generate one. It is emailed/texted to them and must be changed at first sign-in.'),
            TextInput::make('password_confirmation')
                ->password()
                ->revealable()
                ->requiredWith('password')
                ->dehydrated(false),
        ];
    }
}
