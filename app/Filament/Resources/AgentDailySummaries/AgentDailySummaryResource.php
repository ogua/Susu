<?php

namespace App\Filament\Resources\AgentDailySummaries;

use App\Filament\Resources\AgentDailySummaries\Pages\ListAgentDailySummaries;
use App\Filament\Resources\AgentDailySummaries\Tables\AgentDailySummariesTable;
use App\Models\AgentDailySummary;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Day sheets are created by agent activity (SubmitAgentDailySummaryAction) — reconcile only here. */
class AgentDailySummaryResource extends Resource
{
    protected static ?string $model = AgentDailySummary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('branch_id', Filament::getTenant()?->id);
    }

    public static function table(Table $table): Table
    {
        return AgentDailySummariesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentDailySummaries::route('/'),
        ];
    }
}
