<?php

namespace App\Filament\Resources\LoanGroups\RelationManagers;

use App\Actions\GroupLoans\ActivateGroupLoanAction;
use App\Actions\GroupLoans\RecordGroupLoanDepositAction;
use App\Actions\GroupLoans\RecordGroupLoanRepaymentAction;
use App\Actions\GroupLoans\WriteOffGroupLoanAction;
use App\Enums\AccountStatus;
use App\Enums\DepositStatus;
use App\Enums\GroupLoanStatus;
use App\Models\GroupLoan;
use App\Models\SavingsAccount;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The client's paper ledger: one row per member loan with Security Deposit /
 * Amount to be Paid / Loan Outstanding and a Total row. Deposit, activation,
 * repayments and write-off all happen here.
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
                self::recordDepositAction(fn (GroupLoan $record): GroupLoan => $record),
                self::activateAction(fn (GroupLoan $record): GroupLoan => $record),
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
                Action::make('writeOff')
                    ->label('Write off')
                    ->color('danger')
                    ->visible(fn (GroupLoan $record): bool => $record->status->value === 'active')
                    ->authorize(fn (GroupLoan $record): bool => Filament::auth()->user()->can('writeOff', $record))
                    ->requiresConfirmation()
                    ->modalDescription('This permanently closes the loan and recognizes the remaining balance as a loss.')
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
                        Notification::make()->title('Loan written off')->success()->send();
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->defaultSort('issued_at');
    }

    /**
     * Records a draft loan's security deposit into one of the borrower's savings
     * accounts. Shared with the Members tab, which resolves the loan from the member row.
     *
     * @param  Closure(Model): ?GroupLoan  $loanFor
     */
    public static function recordDepositAction(Closure $loanFor): Action
    {
        return Action::make('recordDeposit')
            ->label('Record deposit')
            ->color('warning')
            ->icon(Heroicon::Banknotes)
            ->visible(fn (Model $record): bool => $loanFor($record)?->status === GroupLoanStatus::Draft
                && $loanFor($record)->deposit_status === DepositStatus::Pending)
            ->disabled(fn (Model $record): bool => ! $loanFor($record)?->customer->savingsAccounts()->exists())
            ->tooltip(fn (Model $record): ?string => $loanFor($record)?->customer->savingsAccounts()->exists()
                ? null
                : 'This customer has no savings accounts — open one first.')
            ->authorize(fn (Model $record): bool => Filament::auth()->user()->can('recordDeposit', $loanFor($record)))
            ->modalHeading(fn (Model $record): string => 'Record security deposit for loan '.$loanFor($record)->loan_number)
            ->schema(fn (Model $record): array => [
                Select::make('savings_account_id')
                    ->label('Deposit into savings account')
                    ->options(fn (): array => $loanFor($record)->customer->savingsAccounts()
                        ->where('status', AccountStatus::Active)
                        ->get()
                        ->mapWithKeys(fn (SavingsAccount $account): array => [
                            $account->id => $account->account_number.' — '.Money::format($account->balance),
                        ])
                        ->all())
                    ->required(),
                TextInput::make('amount')->label('Amount (GHS)')->numeric()
                    ->default($loanFor($record)->security_deposit_amount / 100)->readOnly()->required(),
            ])
            ->action(function (array $data, Model $record) use ($loanFor): void {
                app(RecordGroupLoanDepositAction::class)->execute(
                    groupLoan: $loanFor($record),
                    savingsAccount: SavingsAccount::findOrFail($data['savings_account_id']),
                    amount: Money::toMinorUnits($data['amount']),
                    recordedBy: Filament::auth()->user(),
                );
                Notification::make()->title('Deposit recorded')->body('The loan is now ready to activate.')->success()->send();
            });
    }

    /**
     * Activates a draft loan whose deposit is held: generates the schedule and
     * disburses the principal. Shared with the Members tab.
     *
     * @param  Closure(Model): ?GroupLoan  $loanFor
     */
    public static function activateAction(Closure $loanFor): Action
    {
        return Action::make('activate')
            ->label('Activate loan')
            ->color('success')
            ->icon(Heroicon::CheckCircle)
            ->visible(fn (Model $record): bool => $loanFor($record)?->status === GroupLoanStatus::Draft
                && $loanFor($record)->deposit_status === DepositStatus::Held)
            ->authorize(fn (Model $record): bool => Filament::auth()->user()->can('activate', $loanFor($record)))
            ->requiresConfirmation()
            ->modalHeading(fn (Model $record): string => 'Activate loan '.$loanFor($record)->loan_number)
            ->modalDescription('This generates the repayment schedule and disburses the principal.')
            ->action(function (Model $record) use ($loanFor): void {
                app(ActivateGroupLoanAction::class)->execute($loanFor($record), Filament::auth()->user());
                Notification::make()->title('Loan activated')->success()->send();
            });
    }
}
