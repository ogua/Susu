<?php

namespace App\Filament\Resources\Loans\Tables;

use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Loans\RejectLoanAction;
use App\Models\Loan;
use App\Services\Loans\EligibilityService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('loan_number')->searchable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName()),
                TextColumn::make('loanProduct.name')->label('Product'),
                TextColumn::make('principal_amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('outstanding_balance')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('status')->badge(),
                TextColumn::make('eligibility')
                    ->label('Eligibility')
                    ->state(function (Loan $record): string {
                        if ($record->savingsAccount === null) {
                            return 'N/A';
                        }

                        $result = app(EligibilityService::class)->evaluate($record->savingsAccount, $record->principal_amount);

                        return $result->eligible ? 'Eligible' : 'Not eligible: '.implode(' ', $result->reasons);
                    })
                    ->badge()
                    ->color(fn (string $state): string => str_starts_with($state, 'Eligible') ? 'success' : 'danger')
                    ->wrap(),
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
                    ->visible(fn (Loan $record): bool => $record->status->value === 'applied')
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->action(function (Loan $record): void {
                        app(ApproveLoanAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Loan approved')->success()->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (Loan $record): bool => $record->status->value === 'applied')
                    ->authorize('reject')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, Loan $record): void {
                        app(RejectLoanAction::class)->execute($record, Filament::auth()->user(), $data['reason']);
                        Notification::make()->title('Loan rejected')->success()->send();
                    }),
                Action::make('disburse')
                    ->color('primary')
                    ->visible(fn (Loan $record): bool => $record->status->value === 'approved')
                    ->authorize('disburse')
                    ->requiresConfirmation()
                    ->action(function (Loan $record): void {
                        app(DisburseLoanAction::class)->execute($record, Filament::auth()->user());
                        Notification::make()->title('Loan disbursed')->success()->send();
                    }),
                Action::make('recordRepayment')
                    ->label('Record repayment')
                    ->color('gray')
                    ->visible(fn (Loan $record): bool => $record->status->value === 'disbursed')
                    ->authorize('recordRepayment')
                    ->schema([
                        TextInput::make('amount')->label('Amount (GHS)')->numeric()->required(),
                    ])
                    ->action(function (array $data, Loan $record): void {
                        app(RecordLoanRepaymentAction::class)->execute(
                            $record,
                            (int) round((float) $data['amount'] * 100),
                            Filament::auth()->user(),
                        );
                        Notification::make()->title('Repayment recorded')->success()->send();
                    }),
            ])
            ->defaultSort('applied_at', 'desc');
    }
}
