<?php

namespace App\Filament\Pages;

use App\Actions\Reports\BuildIncomeStatementAction;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Profit & loss over a period, computed from posted journal lines. */
class IncomeStatement extends Page
{
    protected string $view = 'filament.pages.income-statement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Income Statement';

    protected static ?string $title = 'Income Statement (Profit & Loss)';

    public ?string $from = null;

    public ?string $until = null;

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->until = now()->toDateString();
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return app(BuildIncomeStatementAction::class)->execute(
            Filament::getTenant()->company,
            filled($this->from) ? CarbonImmutable::parse($this->from) : null,
            filled($this->until) ? CarbonImmutable::parse($this->until) : null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('reports.income-statement.pdf', [
                    'branch' => Filament::getTenant(),
                    ...array_filter(['from' => $this->from, 'to' => $this->until]),
                ]))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.income-statement.excel', [
                    'branch' => Filament::getTenant(),
                    ...array_filter(['from' => $this->from, 'to' => $this->until]),
                ]))
                ->openUrlInNewTab(),
        ];
    }
}
