<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;
use UnitEnum;

/**
 * Every signed-in mobile/desktop device (one Sanctum token per sign-in):
 * who, which company, which app and version, and when it last reached the
 * server. A device silent for days usually means unsynced field records.
 */
class Devices extends Page implements HasTable
{
    use InteractsWithTable;

    /** A device not heard from in this many days is flagged as stale. */
    public const STALE_AFTER_DAYS = 3;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Devices';

    protected static ?string $title = 'Signed-in Devices';

    public function table(Table $table): Table
    {
        return $table
            ->query(PersonalAccessToken::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->with('tokenable.company'))
            ->columns([
                TextColumn::make('tokenable.name')->label('User')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHasMorph('tokenable', [User::class], fn (Builder $users) => $users->where('name', 'like', "%{$search}%"))),
                TextColumn::make('tokenable.company.name')->label('Company')->placeholder('—'),
                TextColumn::make('name')->label('Device'),
                TextColumn::make('client_platform')->label('App')->badge()->placeholder('unknown'),
                TextColumn::make('client_version')->label('Version')->placeholder('unknown'),
                TextColumn::make('last_used_at')->label('Last seen')->since()->sortable()->placeholder('Never')
                    ->color(fn (PersonalAccessToken $record): ?string => $record->last_used_at === null || $record->last_used_at->lt(now()->subDays(self::STALE_AFTER_DAYS)) ? 'danger' : null),
                TextColumn::make('created_at')->label('Signed in')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('company')
                    ->options(fn (): array => Company::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $companyId): Builder => $query->whereHasMorph('tokenable', [User::class], fn (Builder $users) => $users->where('company_id', $companyId)),
                    )),
                SelectFilter::make('client_platform')->label('App')->options(['mobile' => 'Mobile', 'desktop' => 'Desktop']),
                Filter::make('stale')
                    ->label('Not seen for '.self::STALE_AFTER_DAYS.'+ days')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                        ->whereNull('last_used_at')
                        ->orWhere('last_used_at', '<', now()->subDays(self::STALE_AFTER_DAYS)))),
            ])
            ->recordActions([
                Action::make('signOut')
                    ->label('Sign out')
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The device is signed out at its next request. Records it has not synced stay on the device and upload after the user signs in again.')
                    ->action(function (PersonalAccessToken $record): void {
                        $record->delete();

                        Notification::make()->title('Device signed out')->success()->send();
                    }),
            ])
            ->defaultSort('last_used_at', 'desc')
            ->emptyStateHeading('No devices are signed in.');
    }
}
