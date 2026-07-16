<?php

namespace App\Filament\SuperAdmin\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create'),
                        Toggle::make('is_active')->default(true),
                    ]),

                Section::make('Company & Roles')
                    ->columns(2)
                    ->schema([
                        Select::make('company_id')
                            ->label('Company')
                            ->relationship('company', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('branch_id')
                            ->label('Primary branch')
                            ->relationship('branch', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('The branch this user is based at.'),
                        Select::make('branches')
                            ->label('Branch access')
                            ->relationship('branches', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Which branches this user can log into (tenant access) — separate from their primary branch above.')
                            ->columnSpanFull(),
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
