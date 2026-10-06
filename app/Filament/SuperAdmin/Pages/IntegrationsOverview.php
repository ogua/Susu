<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Models\Company;
use App\Models\NotificationLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Each company's SMS (Arkesel) and Paystack connection, with 30-day SMS
 * delivery. A company still on the "log" SMS driver sends nothing real —
 * its customers get no payment receipts — so that is flagged first.
 * Secrets are never shown here; companies manage them on their own
 * SMS & Payments page.
 */
class IntegrationsOverview extends Page implements HasTable
{
    use InteractsWithTable;

    public const WINDOW_DAYS = 30;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Company Integrations';

    public function table(Table $table): Table
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        return $table
            ->query(Company::query()
                ->with(['smsSetting', 'paymentSetting'])
                ->withCount([
                    'notificationLogs as sms_sent_count' => fn (Builder $query) => $query->where('channel', 'sms')->where('status', 'sent')->where('created_at', '>=', $since),
                    'notificationLogs as sms_failed_count' => fn (Builder $query) => $query->where('channel', 'sms')->where('status', 'failed')->where('created_at', '>=', $since),
                ])
                ->withMax(['notificationLogs as last_sms_at' => fn (Builder $query) => $query->where('channel', 'sms')], 'created_at'))
            ->columns([
                TextColumn::make('name')->label('Company')->searchable()->sortable(),
                TextColumn::make('sms_provider')
                    ->label('SMS')
                    ->badge()
                    ->state(fn (Company $record): string => match ($record->smsSetting?->provider) {
                        'arkesel' => 'Arkesel',
                        default => 'Not connected',
                    })
                    ->color(fn (string $state): string => $state === 'Not connected' ? 'danger' : 'success'),
                TextColumn::make('smsSetting.sender_id')->label('Sender ID')->placeholder('—'),
                IconColumn::make('smsSetting.notifications_enabled')->label('Receipts on')->boolean()->default(true),
                TextColumn::make('paystack')
                    ->label('Paystack')
                    ->badge()
                    ->state(fn (Company $record): string => $record->paymentSetting?->hasOwnPaystackAccount() ? 'Own account' : 'Platform account')
                    ->color(fn (string $state): string => $state === 'Own account' ? 'success' : 'gray'),
                TextColumn::make('sms_sent_count')->label('SMS sent ('.self::WINDOW_DAYS.'d)')->sortable()->alignEnd(),
                TextColumn::make('sms_failed_count')->label('Failed')->sortable()->alignEnd()
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('last_sms_at')->label('Last SMS')->since()->placeholder('Never'),
            ])
            ->filters([
                Filter::make('sms_not_connected')
                    ->label('SMS not connected')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                        ->whereDoesntHave('smsSetting')
                        ->orWhereHas('smsSetting', fn (Builder $setting) => $setting->where('provider', 'log')))),
                Filter::make('sms_failures')
                    ->label('Has failed SMS')
                    ->query(fn (Builder $query): Builder => $query->whereHas('notificationLogs', fn (Builder $logs) => $logs
                        ->where('channel', 'sms')->where('status', 'failed')->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS)))),
            ])
            ->recordActions([
                Action::make('failedSms')
                    ->label('Failed SMS')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->visible(fn (Company $record): bool => $record->sms_failed_count > 0)
                    ->modalHeading(fn (Company $record): string => "Failed SMS — {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Company $record): View => view('filament.super-admin.failed-sms', [
                        'logs' => NotificationLog::query()
                            ->where('company_id', $record->id)
                            ->where('channel', 'sms')
                            ->where('status', 'failed')
                            ->latest()
                            ->limit(20)
                            ->get(),
                    ])),
            ])
            ->defaultSort('name');
    }
}
