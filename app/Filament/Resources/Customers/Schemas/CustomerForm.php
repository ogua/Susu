<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\AccountStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Personal details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')->required(),
                        TextInput::make('last_name')->required(),
                        TextInput::make('phone')->tel()->required(),
                        Select::make('gender')->options([
                            'male' => 'Male',
                            'female' => 'Female',
                        ]),
                        DatePicker::make('date_of_birth')->maxDate(now()->subYears(18)),
                        Select::make('status')
                            ->options(AccountStatus::class)
                            ->default(AccountStatus::Active)
                            ->required(),
                        FileUpload::make('photo_path')
                            ->image()
                            ->disk('local')
                            ->directory('customers/photos')
                            ->visibility('private')
                            ->columnSpanFull(),
                    ]),

                Section::make('KYC — Identification')
                    ->columns(2)
                    ->schema([
                        Select::make('id_type')
                            ->options([
                                'ghana_card' => 'Ghana Card',
                                'voters_id' => "Voter's ID",
                                'passport' => 'Passport',
                                'drivers_license' => "Driver's License",
                            ])
                            ->default('ghana_card')
                            ->required(),
                        TextInput::make('id_number')->label('ID number'),
                        FileUpload::make('id_photo_path')
                            ->label('ID photo')
                            ->image()
                            ->disk('local')
                            ->directory('customers/id-photos')
                            ->visibility('private')
                            ->columnSpanFull(),
                    ]),

                Section::make('Next of kin')
                    ->columns(3)
                    ->schema([
                        TextInput::make('next_of_kin_name')->label('Name'),
                        TextInput::make('next_of_kin_phone')->label('Phone')->tel(),
                        TextInput::make('next_of_kin_relationship')->label('Relationship'),
                    ]),

                Section::make('Address')
                    ->schema([
                        Textarea::make('address')->hiddenLabel()->columnSpanFull(),
                    ]),

                Grid::make(2)
                    ->schema([
                        TextInput::make('customer_code')->disabled()->dehydrated(false),
                        TextInput::make('branch.name')->label('Branch')->disabled()->dehydrated(false),
                    ])
                    ->visibleOn('edit'),
            ]);
    }
}
