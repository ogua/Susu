<?php

namespace App\Filament\Widgets;

use App\Actions\Savings\DecideWithdrawalAction;
use App\Enums\WithdrawalStatus;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Checker Inbox: withdrawal requests awaiting a manager decision. Reuses
 * WithdrawalRequestsTable's approve/reject action shapes verbatim (not
 * "pay" — that applies to already-approved requests, out of scope for an
 * inbox of pending items), so the same WithdrawalRequestPolicy gates both
 * surfaces consistently.
 */
class PendingWithdrawalRequestsWidget extends TableWidget
{
    protected static ?string $heading = 'Checker Inbox: Withdrawal Requests';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WithdrawalRequest::query()
                    ->where('branch_id', Filament::getTenant()?->id)
                    ->where('status', WithdrawalStatus::Pending)
                    ->with(['savingsAccount', 'customer'])
            )
            ->columns([
                TextColumn::make('savingsAccount.account_number')->label('Account'),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName()),
                TextColumn::make('amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->action(function (WithdrawalRequest $record): void {
                        app(DecideWithdrawalAction::class)->approve(Filament::auth()->user(), $record);
                        Notification::make()->title('Withdrawal approved')->success()->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->authorize('reject')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, WithdrawalRequest $record): void {
                        app(DecideWithdrawalAction::class)->reject(Filament::auth()->user(), $record, $data['reason']);
                        Notification::make()->title('Withdrawal rejected')->success()->send();
                    }),
            ])
            ->defaultSort('created_at')
            ->emptyStateHeading('No pending withdrawal requests')
            ->paginated(false);
    }
}
