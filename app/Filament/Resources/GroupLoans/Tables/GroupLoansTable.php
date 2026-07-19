<?php

namespace App\Filament\Resources\GroupLoans\Tables;

use App\Actions\GroupLoans\ApproveGroupLoanAction;
use App\Actions\GroupLoans\DisburseGroupLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\RejectGroupLoanAction;
use App\Models\GroupLoan;
use App\Models\GroupLoanBorrower;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
                TextColumn::make('loanProduct.name')->label('Product'),
                TextColumn::make('principal_amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('outstanding_balance')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('status')->badge(),
                TextColumn::make('applied_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'applied' => 'Applied',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'disbursed' => 'Disbursed',
                    'closed' => 'Closed',
                    'written_off' => 'Written off',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'applied')
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->action(function (GroupLoan $record): void {
                        app(ApproveGroupLoanAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Group loan approved')->success()->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'applied')
                    ->authorize('reject')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, GroupLoan $record): void {
                        app(RejectGroupLoanAction::class)->execute($record, Filament::auth()->user(), $data['reason']);
                        Notification::make()->title('Group loan rejected')->success()->send();
                    }),
                Action::make('disburse')
                    ->color('primary')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'approved')
                    ->authorize('disburse')
                    ->requiresConfirmation()
                    ->modalDescription('This splits the principal evenly across the loan group\'s active members and generates the shared repayment schedule.')
                    ->action(function (GroupLoan $record): void {
                        app(DisburseGroupLoanAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Group loan disbursed')->success()->send();
                    }),
                Action::make('recordRepayment')
                    ->label('Record repayment')
                    ->color('gray')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'disbursed')
                    ->authorize('recordRepayment')
                    ->schema(fn (GroupLoan $record): array => [
                        Select::make('group_loan_borrower_id')
                            ->label('Paying member')
                            ->options($record->borrowers()->with('customer')->get()
                                ->mapWithKeys(fn (GroupLoanBorrower $borrower) => [$borrower->id => $borrower->customer->fullName()])
                                ->all())
                            ->required(),
                        TextInput::make('amount')->label('Amount (GHS)')->numeric()->required(),
                    ])
                    ->action(function (array $data, GroupLoan $record): void {
                        app(RecordGroupLoanRepaymentAction::class)->execute(
                            GroupLoanBorrower::findOrFail($data['group_loan_borrower_id']),
                            (int) round((float) $data['amount'] * 100),
                            Filament::auth()->user(),
                        );
                        Notification::make()->title('Repayment recorded')->success()->send();
                    }),
            ])
            ->defaultSort('applied_at', 'desc');
    }
}
