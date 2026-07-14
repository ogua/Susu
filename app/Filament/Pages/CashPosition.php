<?php

namespace App\Filament\Pages;

use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the branch's physical cash currently sits: the branch cash account
 * (money already remitted to the office) plus every field agent's
 * cash-in-hand account (App\Services\Ledger\ChartOfAccounts::agentCash() —
 * its balance IS the agent's expected cash, so this is the manager's
 * reconciliation/robbery-risk list, not a computed estimate).
 */
class CashPosition extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.cash-position';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Cash Position';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    public function totalCash(): int
    {
        return $this->accountsQuery()->get()->sum('balance');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('reports.cash-position.pdf', Filament::getTenant()))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.cash-position.excel', Filament::getTenant()))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * @return Builder<LedgerAccount>
     */
    private function accountsQuery(): Builder
    {
        $branch = Filament::getTenant();

        $agentIds = User::query()
            ->where('branch_id', $branch?->id)
            ->role('field_agent')
            ->pluck('id');

        return LedgerAccount::query()
            ->where('branch_id', $branch?->id)
            ->where(function (Builder $query) use ($agentIds): void {
                $query->whereNull('accountable_type')
                    ->orWhere(function (Builder $query) use ($agentIds): void {
                        $query->where('accountable_type', User::class)
                            ->whereIn('accountable_id', $agentIds);
                    });
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->accountsQuery())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('holder')
                    ->label('Held By')
                    ->state(fn (LedgerAccount $record): string => $record->accountable_type === User::class
                        ? 'Agent' : 'Branch Office'),
                TextColumn::make('balance')
                    ->label('Cash In Hand')
                    ->state(fn (LedgerAccount $record): string => Money::format($record->balance))
                    ->alignEnd(),
            ])
            ->defaultSort('accountable_type')
            ->paginated(false)
            ->emptyStateHeading('No cash accounts for this branch yet.');
    }
}
