<?php

namespace App\Filament\SuperAdmin\Resources\AppVersions;

use App\Enums\AppPlatform;
use App\Filament\SuperAdmin\Resources\AppVersions\Pages\CreateAppVersion;
use App\Filament\SuperAdmin\Resources\AppVersions\Pages\EditAppVersion;
use App\Filament\SuperAdmin\Resources\AppVersions\Pages\ListAppVersions;
use App\Models\AppVersion;
use App\Services\AppUpdatePolicy;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Client releases the mobile and desktop apps check against
 * (GET /api/v1/app/update-check). One row per platform per release.
 */
class AppVersionResource extends Resource
{
    protected static ?string $model = AppVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'App Releases';

    protected static ?string $modelLabel = 'app release';

    protected static ?string $recordTitleAttribute = 'version';

    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    public static function form(Schema $schema): Schema
    {
        $scopeToPlatform = fn (Unique $rule, Get $get): Unique => $rule->where('platform', self::platformValue($get('platform')));

        return $schema
            ->components([
                Section::make('Build')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('platform')
                            ->options(AppPlatform::class)
                            ->default(AppPlatform::Android)
                            ->required()
                            ->live(),
                        TextInput::make('version')
                            ->placeholder('1.2.0')
                            ->helperText('Must match the build: expo.version in app.json, or the desktop pom version.')
                            ->required()
                            ->maxLength(20)
                            ->regex(self::VERSION_PATTERN)
                            ->validationMessages(['regex' => 'Use major.minor.patch, e.g. 1.2.0.'])
                            ->unique(modifyRuleUsing: $scopeToPlatform),
                        TextInput::make('version_code')
                            ->label('Version code')
                            ->helperText('Increasing whole number; the highest active one is the release apps compare against.')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->default(fn (): int => (int) AppVersion::query()->max('version_code') + 1)
                            ->unique(modifyRuleUsing: $scopeToPlatform),
                        TextInput::make('minimum_supported_version')
                            ->label('Minimum supported version')
                            ->placeholder('1.0.0')
                            ->helperText('Builds older than this are blocked until they update. Leave blank for no floor.')
                            ->maxLength(20)
                            ->regex(self::VERSION_PATTERN)
                            ->validationMessages(['regex' => 'Use major.minor.patch, e.g. 1.0.0.'])
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $version = AppUpdatePolicy::normalize($get('version'));

                                if ($value && $version && version_compare(AppUpdatePolicy::normalize($value) ?? '0.0.0', $version, '>')) {
                                    $fail('The minimum supported version cannot be newer than this release.');
                                }
                            }),
                        DateTimePicker::make('released_at')
                            ->default(now()),
                    ]),
                Section::make('Rollout')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Inactive releases are ignored, e.g. one pulled from the store.')
                            ->default(true),
                        Toggle::make('is_forced_update')
                            ->label('Force update')
                            ->helperText('Every device older than this release is blocked until it updates — even after a newer, unforced release.')
                            ->default(false),
                        TextInput::make('store_url')
                            ->label(fn (Get $get): string => self::platformValue($get('platform')) === AppPlatform::Desktop->value ? 'Download URL' : 'Store URL')
                            ->placeholder('https://play.google.com/store/apps/details?id=…')
                            ->helperText('Where the update button sends users. Blank uses the platform default from config/app_updates.php.')
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('release_notes')
                            ->label('Update message')
                            ->helperText('Shown in the update prompt.')
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('platform')->badge()->sortable(),
                TextColumn::make('version')->weight('bold')->searchable(),
                TextColumn::make('version_code')->label('Code')->numeric()->sortable(),
                TextColumn::make('minimum_supported_version')->label('Minimum')->placeholder('—'),
                IconColumn::make('is_forced_update')->label('Forced')->boolean()
                    ->trueColor('danger')->falseColor('gray'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('released_at')->dateTime('d M Y')->sortable(),
                TextColumn::make('creator.name')->label('By')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform')->options(AppPlatform::class),
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_forced_update')->label('Forced'),
            ])
            ->recordActions([
                Action::make('deactivate')
                    ->label('Pull release')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Apps stop being offered this release; the next newest active release takes its place.')
                    ->visible(fn (AppVersion $record): bool => $record->is_active)
                    ->action(function (AppVersion $record): void {
                        $record->update(['is_active' => false]);
                        Notification::make()->title('Release pulled')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('version_code', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppVersions::route('/'),
            'create' => CreateAppVersion::route('/create'),
            'edit' => EditAppVersion::route('/{record}/edit'),
        ];
    }

    /** $get() on an enum Select returns the enum instance once hydrated, a string before. */
    private static function platformValue(mixed $platform): ?string
    {
        return $platform instanceof AppPlatform ? $platform->value : $platform;
    }
}
