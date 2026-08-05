<?php

namespace App\Filament\Pages;

use App\Actions\Agents\SubmitAgentDailySummaryAction;
use App\Enums\AgentSummaryStatus;
use App\Models\AgentDailySummary;
use App\Services\Ledger\ChartOfAccounts;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Field agent's own day-close: declare the cash on hand against the agent's
 * cash-in-hand ledger balance (App\Services\Ledger\ChartOfAccounts::agentCash).
 * Mirrors the mobile app's day-close screen and the same
 * SubmitAgentDailySummaryAction, so a day sheet started on one platform is
 * visible/completable on the other.
 */
class CloseDaily extends Page
{
    protected string $view = 'filament.pages.close-daily';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Close Daily';

    public AgentDailySummary $summary;

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole('field_agent') ?? false;
    }

    public function mount(): void
    {
        $this->loadSummary();
    }

    public function expectedCash(): int
    {
        return app(ChartOfAccounts::class)->agentCash(Filament::auth()->user())->refresh()->balance;
    }

    private function loadSummary(): void
    {
        $agent = Filament::auth()->user();

        $this->summary = AgentDailySummary::firstOrCreate(
            ['agent_id' => $agent->id, 'summary_date' => now()->toDateString()],
            ['company_id' => $agent->company_id, 'branch_id' => $agent->branch_id],
        );
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeDay')
                ->label(fn (): string => $this->summary->status === AgentSummaryStatus::Open ? 'Close Day' : 'Update Day Sheet')
                ->visible(fn (): bool => $this->summary->status !== AgentSummaryStatus::Reconciled)
                ->schema([
                    TextInput::make('declared_cash')
                        ->label('Cash you are holding (GHS)')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    Textarea::make('notes')
                        ->maxLength(500),
                ])
                ->fillForm(fn (): array => [
                    'notes' => $this->summary->notes,
                ])
                ->action(function (array $data): void {
                    app(SubmitAgentDailySummaryAction::class)->execute(
                        agent: Filament::auth()->user(),
                        declaredCash: Money::toMinorUnits($data['declared_cash']),
                        notes: $data['notes'] ?? null,
                    );

                    $this->loadSummary();

                    Notification::make()->title('Day sheet submitted')->success()->send();
                }),
        ];
    }
}
