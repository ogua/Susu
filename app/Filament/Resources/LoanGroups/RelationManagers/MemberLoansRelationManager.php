<?php

namespace App\Filament\Resources\LoanGroups\RelationManagers;

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\ApplyGroupLoanDepositAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\WriteOffGroupLoanAction;
use App\Models\GroupLoan;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The client's paper ledger: one row per member loan with Security Deposit /
 * Amount to be Paid / Loan Outstanding and a Total row. Deposit, activation,
 * repayments, deposit-offset and write-off all happen here.
 */
class MemberLoansRelationManager extends RelationManager
{
    protected static string $relationship = 'groupLoans';

    protected static ?string $title = 'Member Loans';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('loan_number')
            ->columns([
                TextColumn::make('customer.first_name')
                    ->label('Members name')
                    ->formatStateUsing(fn (GroupLoan $record): string => $record->customer->fullName()),
                TextColumn::make('security_deposit_amount')
                    ->label('Security deposit')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn (int $state): string => Money::format($state))),
                TextColumn::make('periodic_amount')
                    ->label('Amount to be paid')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn (int $state): string => Money::format($state))),
                TextColumn::make('outstanding_balance')
                    ->label('Loan outstanding')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn (int $state): string => Money::format($state))),
                TextColumn::make('deposit_status')->badge(),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                Action::make('recordDeposit')
                    ->label('Record deposit')
                    ->color('gray')
                    ->visible(fn (GroupLoan $record): bool => $record->deposit_status->value === 'pending')
                    ->authorize(fn (GroupLoan $record): bool => Filament::auth()->user()->can('recordDeposit', $record))
                    ->schema(fn (GroupLoan $record): array => [
                        TextInput::make('amount')->label('Amount (GHS)')->numeric()
                            ->default($record->security_deposit_amount / 100)->readOnly()->required(),
                    ])
                    ->action(function (array $data, GroupLoan $record): void {
                        app(RecordGroupLoanDepositAction::class)->execute(
                            groupLoan: $record,
                            amount: Money::toMinorUnits($data['amount']),
                            recordedBy: Filament::auth()->user(),
                        );
                        Notification::make()->title('Deposit recorded')->success()->send();
                    }),
                Action::make('activate')
                    ->color('primary')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'draft' && $record->deposit_status->value === 'held')
                    ->authorize(fn (GroupLoan $record): bool => Filament::auth()->user()->can('activate', $record))
                    ->requiresConfirmation()
                    ->modalDescription('This generates the repayment schedule and disburses the principal.')
                    ->action(function (GroupLoan $record): void {
                        app(ActivateGroupLoanAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Loan activated')->success()->send();
                    }),
                Action::make('recordRepayment')
                    ->label('Record repayment')
                    ->color('gray')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'active')
                    ->authorize(fn (GroupLoan $record): bool => Filament::auth()->user()->can('recordRepayment', $record))
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
                Action::make('applyDeposit')
                    ->label('Apply deposit to balance')
                    ->color('warning')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'active' && $record->deposit_status->value === 'held')
                    ->authorize(fn (GroupLoan $record): bool => Filament::auth()->user()->can('applyDeposit', $record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (GroupLoan $record): string => 'This offsets the '.Money::format($record->security_deposit_amount)
                        .' deposit against the '.Money::format($record->outstanding_balance).' outstanding balance; any excess is refunded in cash.')
                    ->action(function (GroupLoan $record): void {
                        app(ApplyGroupLoanDepositAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Deposit applied to balance')->success()->send();
                    }),
                Action::make('writeOff')
                    ->label('Write off')
                    ->color('danger')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'active')
                    ->authorize(fn (GroupLoan $record): bool => Filament::auth()->user()->can('writeOff', $record))
                    ->requiresConfirmation()
                    ->modalDescription('This permanently closes the loan and recognizes the remaining balance as a loss.')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, GroupLoan $record): void {
                        app(WriteOffGroupLoanAction::class)->execute($record, Filament::auth()->user(), $data['reason']);
                        Notification::make()->title('Loan written off')->success()->send();
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->defaultSort('issued_at');
    }
}
