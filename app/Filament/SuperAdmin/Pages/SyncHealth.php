<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Enums\ClientOrigin;
use App\Enums\SyncOpType;
use App\Filament\SuperAdmin\Widgets\SyncHealthOverview;
use App\Models\Company;
use App\Models\SyncOp;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Offline-sync operations as the server recorded them, rejections first.
 * A rejected op is final (the device shows it on its Sync screen); retryable
 * failures are deliberately not stored, so they never appear here.
 */
class SyncHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Sync Health';

    protected static ?string $title = 'Sync Health';

    protected function getHeaderWidgets(): array
    {
        return [
            SyncHealthOverview::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SyncOp::query()->with(['company', 'actor']))
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
                TextColumn::make('recorded_at')->label('Recorded on device')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('company.name')->label('Company')->placeholder('—'),
                TextColumn::make('actor.name')->label('User')->placeholder('—'),
                TextColumn::make('origin')->badge(),
                TextColumn::make('op_type')->label('Operation'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => $state === 'rejected' ? 'danger' : 'success'),
                TextColumn::make('errors')
                    ->state(fn (SyncOp $record): string => collect($record->result['errors'] ?? [])->join(' · ') ?: '—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['rejected' => 'Rejected', 'applied' => 'Applied'])
                    ->default('rejected'),
                SelectFilter::make('company_id')
                    ->label('Company')
                    ->options(fn (): array => Company::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('op_type')->label('Operation')->options(SyncOpType::class),
                SelectFilter::make('origin')->options(ClientOrigin::class),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until')->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No sync operations match these filters.');
    }
}
