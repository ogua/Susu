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
                ->helperText('They sign in to the admin panel with this email.'),
            TextInput::make('phone')->tel()->maxLength(32),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->required()
                ->minLength(8)
                ->confirmed(),
            TextInput::make('password_confirmation')
                ->password()
                ->revealable()
                ->required()
                ->dehydrated(false),
        ];
    }
}
