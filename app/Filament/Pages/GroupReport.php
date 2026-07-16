<?php

namespace App\Filament\Pages;

use App\Models\Group;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Susu (ROSCA) group health for the branch — on-page view of the group report. */
class GroupReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Susu Groups';

    protected static ?string $title = 'Susu Groups Report';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('reports.groups.pdf', Filament::getTenant()))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.groups.excel', Filament::getTenant()))
                ->openUrlInNewTab(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Group::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->withCount('members')
                    ->withSum('rounds as lifetime_collected', 'total_collected')
                    ->with('rounds'),
            )
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('members_count')->label('Members')->alignEnd(),
                TextColumn::make('current_round')
                    ->label('Round')
                    ->state(fn (Group $record): string => (string) ($record->currentRound()?->round_number ?? '—'))
                    ->alignEnd(),
                TextColumn::make('round_progress')
                    ->label('Round Collected / Expected')
                    ->state(function (Group $record): string {
                        $round = $record->currentRound();

                        if ($round === null) {
                            return '—';
                        }

                        return Money::format((int) $round->total_collected).' / '.Money::format((int) $round->total_expected);
                    })
                    ->alignEnd(),
                TextColumn::make('lifetime_collected')
                    ->label('Lifetime Collected')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => Money::format((int) $state)),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'active' => 'Active',
                    'completed' => 'Completed',
                ]),
            ])
            ->defaultSort('name');
    }
}
