<?php

namespace App\Filament\SuperAdmin\Resources\Companies\RelationManagers;

use App\Models\CompanyExport;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * The company's data exports; started from the page's "Export all data"
 * action and deleted by exports:prune after platform.export_retention_days.
 */
class ExportsRelationManager extends RelationManager
{
    protected static string $relationship = 'exports';

    protected static ?string $title = 'Exports';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('requestedBy.name')->label('By')->placeholder('—'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CompanyExport::STATUS_READY => 'success',
                        CompanyExport::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('size_bytes')->label('Size')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : '—'),
                TextColumn::make('completed_at')->label('Ready')->dateTime()->placeholder('—'),
                TextColumn::make('error')->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (CompanyExport $record): bool => $record->status === CompanyExport::STATUS_READY)
                    ->url(fn (CompanyExport $record): string => route('platform-exports.download', $record))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc')
            ->description(fn (): string => 'Exports are deleted automatically after '.config('platform.export_retention_days').' days.')
            ->poll('10s');
    }
}
