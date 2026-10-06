<?php

namespace App\Filament\SuperAdmin\Resources\Announcements;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementLevel;
use App\Filament\SuperAdmin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\SuperAdmin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\SuperAdmin\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use BackedEnum;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Notices from the SusuApp team to tenant staff — shown as a banner in the
 * admin panel and on the apps' home screens while running.
 */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('title')->required()->maxLength(150)->columnSpanFull(),
                        Textarea::make('body')->required()->maxLength(1000)->rows(4)->columnSpanFull(),
                        Select::make('level')->options(AnnouncementLevel::class)->default(AnnouncementLevel::Info)->required(),
                        Select::make('audience')
                            ->options([
                                AnnouncementAudience::AllStaff->value => 'All staff',
                                AnnouncementAudience::CompanyAdmins->value => 'Company admins only',
                            ])
                            ->default(AnnouncementAudience::AllStaff->value)
                            ->required(),
                        DateTimePicker::make('starts_at')->default(now())->required(),
                        DateTimePicker::make('ends_at')->after('starts_at')->helperText('Leave blank to run until removed.'),
                        Toggle::make('notify_now')
                            ->label('Also send to their notification bell now')
                            ->visibleOn('create')
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('level')->badge()
                    ->color(fn (AnnouncementLevel $state): string => match ($state) {
                        AnnouncementLevel::Critical => 'danger',
                        AnnouncementLevel::Warning => 'warning',
                        AnnouncementLevel::Info => 'info',
                    }),
                TextColumn::make('audience')->badge(),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->placeholder('Until removed'),
                TextColumn::make('state')
                    ->badge()
                    ->state(fn (Announcement $record): string => match (true) {
                        $record->starts_at->isFuture() => 'Scheduled',
                        $record->ends_at !== null && $record->ends_at->isPast() => 'Ended',
                        default => 'Running',
                    })
                    ->color(fn (string $state): string => $state === 'Running' ? 'success' : 'gray'),
                TextColumn::make('creator.name')->label('By')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('end')
                    ->label('End now')
                    ->icon(Heroicon::OutlinedStopCircle)
                    ->requiresConfirmation()
                    ->visible(fn (Announcement $record): bool => $record->ends_at === null || $record->ends_at->isFuture())
                    ->action(function (Announcement $record): void {
                        $record->update(['ends_at' => now()]);
                        Notification::make()->title('Announcement ended')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
