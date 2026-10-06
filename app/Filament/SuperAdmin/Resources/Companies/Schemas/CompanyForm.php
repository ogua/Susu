<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The edit form. CreateCompany reuses these sections as the first steps of
 * its onboarding wizard, so the two never drift apart.
 */
class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::detailsSection(),
                self::brandingSection(),
                self::contactSection(),
            ]);
    }

    public static function detailsSection(): Section
    {
        return Section::make('Company details')
            ->columns(2)
            ->schema(self::detailsFields());
    }

    /**
     * @return array<int, mixed>
     */
    public static function detailsFields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                    if ($operation === 'create' && blank($get('slug'))) {
                        $set('slug', Str::slug($state ?? ''));
                    }
                }),
            TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
            TextInput::make('domain_alias')->required()->unique(ignoreRecord: true)
                ->helperText('Custom domain this company is reachable on.'),
            TextInput::make('website')->url(),
            Textarea::make('description')->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Inactive companies are suspended: their staff and customers cannot log in on the web or the apps.')
                ->default(true)
                ->columnSpanFull(),
        ];
    }

    public static function brandingSection(): Section
    {
        return Section::make('Branding')
            ->columns(2)
            ->schema(self::brandingFields());
    }

    /**
     * @return array<int, mixed>
     */
    public static function brandingFields(): array
    {
        return [
            FileUpload::make('logo')
                ->image()
                ->disk('public')
                ->directory('companies/logos')
                ->visibility('public')
                ->columnSpanFull(),
            ColorPicker::make('primary_color')->required()->default('#3b82f6'),
            ColorPicker::make('secondary_color')->required()->default('#1e40af'),
        ];
    }

    public static function contactSection(): Section
    {
        return Section::make('Contact')
            ->schema(self::contactFields());
    }

    /**
     * @return array<int, mixed>
     */
    public static function contactFields(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('contact_email')->email()->required(),
                TextInput::make('contact_phone')->tel()->required(),
            ]),
            Textarea::make('address')->columnSpanFull(),
        ];
    }
}
