<?php

namespace App\Filament\SuperAdmin\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branch pickers only offer the selected company's branches, so a user can
 * never be pointed at another tenant's branch. Company admins are granted
 * every branch of their company on save (see SyncCompanyAdminBranchAccessAction).
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                        TextInput::make('phone')->tel(),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave blank to keep the current password.' : null),
                        Toggle::make('is_active')->default(true),
                    ]),

                Section::make('Company & Roles')
                    ->columns(2)
                    ->schema([
                        Select::make('company_id')
                            ->label('Company')
                            ->relationship('company', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('branch_id', null);
                                $set('branches', []);
                            })
                            ->helperText('Leave empty only for platform super admins.'),
                        Select::make('branch_id')
                            ->label('Primary branch')
                            ->relationship('branch', 'name', fn (Builder $query, Get $get) => $query->where('company_id', $get('company_id')))
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get): bool => blank($get('company_id')))
                            ->helperText('The branch this user is based at.'),
                        Select::make('branches')
                            ->label('Branch access')
                            ->relationship('branches', 'name', fn (Builder $query, Get $get) => $query->where('company_id', $get('company_id')))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get): bool => blank($get('company_id')))
                            ->helperText('Which branches this user can log into. Company admins automatically get every branch of their company.')
                            ->columnSpanFull(),
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
