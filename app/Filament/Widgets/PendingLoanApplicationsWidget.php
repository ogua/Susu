<?php

namespace App\Filament\Widgets;

use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\RejectLoanAction;
use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Checker Inbox: loan applications awaiting a manager decision. Reuses
 * LoansTable's approve/reject action shapes verbatim, so the same
 * LoanPolicy gates both surfaces consistently.
 */
class PendingLoanApplicationsWidget extends TableWidget
{
    protected static ?string $heading = 'Checker Inbox: Loan Applications';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Loan::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->where('status', LoanStatus::Applied)
                    ->with('customer')
            )
            ->columns([
                TextColumn::make('loan_number'),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName()),
                TextColumn::make('principal_amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('applied_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->action(function (Loan $record): void {
                        app(ApproveLoanAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Loan approved')->success()->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->authorize('reject')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, Loan $record): void {
                        app(RejectLoanAction::class)->execute($record, Filament::auth()->user(), $data['reason']);
                        Notification::make()->title('Loan rejected')->success()->send();
                    }),
            ])
            ->defaultSort('applied_at')
            ->emptyStateHeading('No pending loan applications')
            ->paginated(false);
    }
}
