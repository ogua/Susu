<?php

namespace App\Filament\Pages;

use App\Actions\Reports\BuildAgentPerformanceReportAction;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Per-agent collections, variance, and commissions over a period. */
class AgentPerformanceReport extends Page
{
    protected string $view = 'filament.pages.agent-performance-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Agent Performance';

    protected static ?string $title = 'Agent Performance & Commissions';

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
        return app(BuildAgentPerformanceReportAction::class)->execute(
            Filament::getTenant(),
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
                ->url(fn (): string => route('reports.agent-performance.pdf', [
                    'branch' => Filament::getTenant(),
                    ...$this->dateParams(),
                ]))
                ->openUrlInNewTab(),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(fn (): string => route('reports.agent-performance.excel', [
                    'branch' => Filament::getTenant(),
                    ...$this->dateParams(),
                ]))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function dateParams(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->until,
        ]);
    }
}
