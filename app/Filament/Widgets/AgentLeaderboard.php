<?php

namespace App\Filament\Widgets;

use App\Models\AgentDailySummary;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Ranks today's collectors within the tenant branch — highest collections total first. */
class AgentLeaderboard extends TableWidget
{
    protected static ?string $heading = "Today's Leaderboard";

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AgentDailySummary::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->where('summary_date', now()->toDateString())
                    ->with('agent')
            )
            ->columns([
                TextColumn::make('agent.name')->label('Agent'),
                TextColumn::make('collections_count')->label('Collections')->sortable(),
                TextColumn::make('collections_total')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->sortable(),
            ])
            ->defaultSort('collections_total', 'desc')
            ->paginated(false);
    }
}
