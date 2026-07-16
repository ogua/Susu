<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
                        TextInput::make('domain_alias')->required()->unique(ignoreRecord: true)
                            ->helperText('Custom domain this company is reachable on.'),
                        TextInput::make('website')->url(),
                        Textarea::make('description')->columnSpanFull(),
                        Toggle::make('is_active')->default(true)->columnSpanFull(),
                    ]),

                Section::make('Branding')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('logo')
                            ->image()
                            ->disk('public')
                            ->directory('companies/logos')
                            ->visibility('public')
                            ->columnSpanFull(),
                        ColorPicker::make('primary_color')->required()->default('#3b82f6'),
                        ColorPicker::make('secondary_color')->required()->default('#1e40af'),
                    ]),

                Section::make('Contact')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('contact_email')->email()->required(),
                            TextInput::make('contact_phone')->tel()->required(),
                        ]),
                        Textarea::make('address')->columnSpanFull(),
                    ]),
            ]);
    }
}
