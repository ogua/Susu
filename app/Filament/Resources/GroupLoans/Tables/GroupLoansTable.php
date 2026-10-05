<?php

namespace App\Filament\Resources\GroupLoans\Tables;

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\WriteOffGroupLoanAction;
use App\Enums\AccountStatus;
use App\Filament\Resources\LoanGroups\RelationManagers\MemberLoansRelationManager;
use App\Filament\Resources\Loans\LoanActions;
use App\Models\GroupLoan;
use App\Models\SavingsAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GroupLoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('loan_number')->searchable(),
                TextColumn::make('loanGroup.name')->label('Loan group'),
                TextColumn::make('customer.first_name')
                    ->label('Member')
                    ->formatStateUsing(fn (GroupLoan $record): string => $record->customer->fullName()),
                TextColumn::make('principal_amount')->label('Loan amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('security_deposit_amount')->label('Security deposit')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('periodic_amount')->label('Amount to be paid')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('outstanding_balance')->label('Loan outstanding')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('deposit_status')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('start_date')->date(),
                TextColumn::make('issued_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'active' => 'Active',
                    'closed' => 'Closed',
                    'written_off' => 'Written off',
                    'cancelled' => 'Cancelled',
                ]),
                SelectFilter::make('loan_group')->relationship('loanGroup', 'name'),
            ])
            ->recordActions([
                Action::make('recordDeposit')
                    ->label('Record deposit')
                    ->color('gray')
                    ->visible(fn (GroupLoan $record): bool => $record->deposit_status->value === 'pending')
                    ->disabled(fn (GroupLoan $record): bool => ! $record->customer->savingsAccounts()->exists())
                    ->authorize('recordDeposit')
                    ->schema(fn (GroupLoan $record): array => [
                        Select::make('savings_account_id')
                            ->label('Deposit into savings account')
                            ->options(fn (): array => $record->customer->savingsAccounts()
                                ->where('status', AccountStatus::Active)
                                ->get()
                                ->mapWithKeys(fn (SavingsAccount $account): array => [
                                    $account->id => $account->account_number.' — '.Money::format($account->balance),
                                ])
                                ->all())
                            ->helperText(fn (): ?string => $record->customer->savingsAccounts()->exists()
                                ? null
                                : 'This customer has no savings accounts — open one first.')
                            ->required(),
                        TextInput::make('amount')
                            ->label('Amount (GHS)')
                            ->numeric()
                            ->default($record->security_deposit_amount / 100)
                            ->readOnly()
                            ->required(),
                    ])
                    ->action(function (array $data, GroupLoan $record): void {
                        app(RecordGroupLoanDepositAction::class)->execute(
                            groupLoan: $record,
                            savingsAccount: SavingsAccount::findOrFail($data['savings_account_id']),
                            amount: Money::toMinorUnits($data['amount']),
                            recordedBy: Filament::auth()->user(),
                        );
                        Notification::make()->title('Deposit recorded')->success()->send();
                    }),
                Action::make('activate')
                    ->color('primary')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'draft' && $record->deposit_status->value === 'held')
                    ->authorize('activate')
                    ->requiresConfirmation()
                    ->modalDescription('This generates the member\'s repayment schedule and disburses the principal.')
                    ->action(function (GroupLoan $record): void {
                        app(ActivateGroupLoanAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Group loan activated')->success()->send();
                    }),
                MemberLoansRelationManager::cancelAction(fn (GroupLoan $record): GroupLoan => $record),
                Action::make('recordRepayment')
                    ->label('Record repayment')
                    ->color('gray')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'active')
                    ->authorize('recordRepayment')
                    ->schema([
                        TextInput::make('amount')->label('Amount (GHS)')->numeric()->required(),
                    ])
                    ->action(function (array $data, GroupLoan $record): void {
                        app(RecordGroupLoanRepaymentAction::class)->execute(
                            groupLoan: $record,
                            amount: Money::toMinorUnits($data['amount']),
                            recordedBy: Filament::auth()->user(),
                        );
                        Notification::make()->title('Repayment recorded')->success()->send();
                    }),
                LoanActions::recalculateSchedule(),
                Action::make('writeOff')
                    ->label('Write off')
                    ->color('danger')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'active')
                    ->authorize('writeOff')
                    ->requiresConfirmation()
                    ->modalDescription('This permanently closes the loan and recognizes the remaining balance as a loss. This cannot be undone.')
                    ->schema(fn (GroupLoan $record): array => [
                        Placeholder::make('total_savings')
                            ->label('Total savings balance')
                            ->content(Money::format($record->customer->savingsAccounts()->where('status', AccountStatus::Active)->sum('balance'))),
                        Select::make('savings_account_id')
                            ->label('Apply from savings account')
                            ->options($record->customer->savingsAccounts()
                                ->where('status', AccountStatus::Active)
                                ->get()
                                ->mapWithKeys(fn (SavingsAccount $account): array => [
                                    $account->id => $account->account_number.' — '.Money::format($account->balance),
                                ])
                                ->all()),
                        TextInput::make('savings_amount_applied')
                            ->label('Amount to apply (GHS)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, GroupLoan $record): void {
                        app(WriteOffGroupLoanAction::class)->execute(
                            $record,
                            Filament::auth()->user(),
                            $data['reason'],
                            savingsAccount: filled($data['savings_account_id'] ?? null) ? SavingsAccount::find($data['savings_account_id']) : null,
                            savingsAmountApplied: Money::toMinorUnits($data['savings_amount_applied'] ?? 0),
                        );
                        Notification::make()->title('Group loan written off')->success()->send();
                    }),
            ])
            ->defaultSort('issued_at', 'desc');
    }
}
