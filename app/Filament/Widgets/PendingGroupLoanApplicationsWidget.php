<?php

namespace App\Filament\Widgets;

use App\Actions\GroupLoans\ApproveGroupLoanAction;
use App\Actions\GroupLoans\RejectGroupLoanAction;
use App\Enums\GroupLoanStatus;
use App\Models\GroupLoan;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Checker Inbox: group loan applications awaiting a manager decision.
 * Reuses GroupLoansTable's approve/reject action shapes verbatim, so the
 * same GroupLoanPolicy gates both surfaces consistently.
 */
class PendingGroupLoanApplicationsWidget extends TableWidget
{
    protected static ?string $heading = 'Checker Inbox: Group Loan Applications';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                GroupLoan::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->where('status', GroupLoanStatus::Applied)
                    ->with('loanGroup')
            )
            ->columns([
                TextColumn::make('loan_number'),
                TextColumn::make('loanGroup.name')->label('Loan group'),
                TextColumn::make('principal_amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('applied_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->action(function (GroupLoan $record): void {
                        app(ApproveGroupLoanAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Group loan approved')->success()->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->authorize('reject')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, GroupLoan $record): void {
                        app(RejectGroupLoanAction::class)->execute($record, Filament::auth()->user(), $data['reason']);
                        Notification::make()->title('Group loan rejected')->success()->send();
                    }),
            ])
            ->defaultSort('applied_at')
            ->emptyStateHeading('No pending group loan applications')
            ->paginated(false);
    }
}
