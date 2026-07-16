<?php

namespace App\Filament\Pages;

use App\Actions\Reports\BuildBalanceSheetAction;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Statement of financial position as at a date. */
class BalanceSheet extends Page
{
    protected string $view = 'filament.pages.balance-sheet';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Balance Sheet';

    protected static ?string $title = 'Balance Sheet';

    public ?string $asAt = null;

    public function mount(): void
    {
        $this->asAt = now()->toDateString();
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
        return app(BuildBalanceSheetAction::class)->execute(
            Filament::getTenant()->company,
            filled($this->asAt) ? CarbonImmutable::parse($this->asAt) : null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('reports.balance-sheet.pdf', [
                    'branch' => Filament::getTenant(),
                    ...array_filter(['as_at' => $this->asAt]),
                ]))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.balance-sheet.excel', [
                    'branch' => Filament::getTenant(),
                    ...array_filter(['as_at' => $this->asAt]),
                ]))
                ->openUrlInNewTab(),
        ];
    }
}
