<?php

namespace App\Filament\Resources\Loans\Tables;

use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Loans\RejectLoanAction;
use App\Actions\Loans\RestructureLoanAction;
use App\Actions\Loans\TopUpLoanAction;
use App\Actions\Loans\WriteOffLoanAction;
use App\Enums\AccountStatus;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Services\Loans\EligibilityService;
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
                TextColumn::make('previousLoan.loan_number')
                    ->label('Restructured/topped up from')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'applied' => 'Applied',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'disbursed' => 'Disbursed',
                    'closed' => 'Closed',
                    'written_off' => 'Written off',
                    'refinanced' => 'Refinanced',
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
                Action::make('restructure')
                    ->color('warning')
                    ->visible(fn (Loan $record): bool => $record->status->value === 'disbursed')
                    ->authorize('restructure')
                    ->requiresConfirmation()
                    ->modalDescription('This closes the current loan and opens a new one carrying over its outstanding principal onto new terms. This cannot be undone.')
                    ->schema(fn (Loan $record): array => [
                        Select::make('loan_product_id')
                            ->label('New loan product')
                            ->options(LoanProduct::where('company_id', $record->company_id)->where('is_active', true)->pluck('name', 'id'))
                            ->default($record->loan_product_id)
                            ->required(),
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, Loan $record): void {
                        $newLoan = app(RestructureLoanAction::class)->execute(
                            $record,
                            Filament::auth()->user(),
                            LoanProduct::findOrFail($data['loan_product_id']),
                            $data['reason'],
                        );
                        Notification::make()->title("Loan restructured into {$newLoan->loan_number}")->success()->send();
                    }),
                Action::make('topUp')
                    ->label('Top up')
                    ->color('info')
                    ->visible(fn (Loan $record): bool => $record->status->value === 'disbursed')
                    ->authorize('topUp')
                    ->requiresConfirmation()
                    ->modalDescription('This closes the current loan and opens a new one for the rolled-over balance plus the top-up cash disbursed today.')
                    ->schema([
                        TextInput::make('amount')->label('Top-up amount (GHS)')->numeric()->required(),
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, Loan $record): void {
                        $newLoan = app(TopUpLoanAction::class)->execute(
                            $record,
                            Filament::auth()->user(),
                            (int) round((float) $data['amount'] * 100),
                            $data['reason'],
                        );
                        Notification::make()->title("Loan topped up into {$newLoan->loan_number}")->success()->send();
                    }),
                Action::make('writeOff')
                    ->label('Write off')
                    ->color('danger')
                    ->visible(fn (Loan $record): bool => $record->status->value === 'disbursed')
                    ->authorize('writeOff')
                    ->requiresConfirmation()
                    ->modalDescription('This permanently closes the loan and recognizes the remaining balance as a loss. This cannot be undone.')
                    ->schema(fn (Loan $record): array => [
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
                    ->action(function (array $data, Loan $record): void {
                        app(WriteOffLoanAction::class)->execute(
                            $record,
                            Filament::auth()->user(),
                            $data['reason'],
                            savingsAccount: filled($data['savings_account_id'] ?? null) ? SavingsAccount::find($data['savings_account_id']) : null,
                            savingsAmountApplied: Money::toMinorUnits($data['savings_amount_applied'] ?? 0),
                        );
                        Notification::make()->title('Loan written off')->success()->send();
                    }),
            ])
            ->defaultSort('applied_at', 'desc');
    }
}
