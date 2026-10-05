<?php

namespace App\Filament\Resources\Loans;

use App\Actions\Loans\ApproveLoanAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\RecalculateRepaymentScheduleAction;
use App\Actions\Loans\RecordLoanRepaymentAction;
use App\Actions\Loans\RejectLoanAction;
use App\Actions\Loans\RestructureLoanAction;
use App\Actions\Loans\TopUpLoanAction;
use App\Actions\Loans\WriteOffLoanAction;
use App\Enums\AccountStatus;
use App\Models\GroupLoan;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Loan lifecycle actions, shared by the loans table and the loan view page.
 * Each delegates to the same action class the API and sync use.
 */
class LoanActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            self::approve(),
            self::reject(),
            self::disburse(),
            self::recordRepayment(),
            self::recalculateSchedule(),
            self::restructure(),
            self::topUp(),
            self::writeOff(),
        ];
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->color('success')
            ->icon(Heroicon::CheckCircle)
            ->visible(fn (Loan $record): bool => $record->status->value === 'applied')
            ->authorize('approve')
            ->requiresConfirmation()
            ->action(function (Loan $record): void {
                app(ApproveLoanAction::class)->execute($record, Filament::auth()->user());
                Notification::make()->title('Loan approved')->success()->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->color('danger')
            ->icon(Heroicon::XCircle)
            ->visible(fn (Loan $record): bool => $record->status->value === 'applied')
            ->authorize('reject')
            ->schema([
                Textarea::make('reason')->required(),
            ])
            ->action(function (array $data, Loan $record): void {
                app(RejectLoanAction::class)->execute($record, Filament::auth()->user(), $data['reason']);
                Notification::make()->title('Loan rejected')->success()->send();
            });
    }

    public static function disburse(): Action
    {
        return Action::make('disburse')
            ->color('primary')
            ->icon(Heroicon::Banknotes)
            ->visible(fn (Loan $record): bool => $record->status->value === 'approved')
            ->authorize('disburse')
            ->requiresConfirmation()
            ->modalDescription(fn (Loan $record): string => 'Cash out: '.Money::format($record->principal_amount - $record->origination_fee_amount)
                .($record->origination_fee_amount > 0 ? ' (after '.Money::format($record->origination_fee_amount).' charges).' : '.'))
            ->action(function (Loan $record): void {
                app(DisburseLoanAction::class)->execute($record, Filament::auth()->user());
                Notification::make()->title('Loan disbursed')->success()->send();
            });
    }

    public static function recordRepayment(): Action
    {
        return Action::make('recordRepayment')
            ->label('Repayment')
            ->color('gray')
            ->icon(Heroicon::Banknotes)
            ->visible(fn (Loan $record): bool => $record->status->value === 'disbursed')
            ->authorize('recordRepayment')
            ->schema([
                TextInput::make('amount')->label('Amount (GHS)')->numeric()->minValue(0.01)->required(),
            ])
            ->action(function (array $data, Loan $record): void {
                app(RecordLoanRepaymentAction::class)->execute(
                    $record,
                    Money::toMinorUnits($data['amount']),
                    Filament::auth()->user(),
                );
                Notification::make()->title('Repayment recorded')->success()->send();
            });
    }

    /** Re-date unpaid installments — works for individual and group member loans. */
    public static function recalculateSchedule(): Action
    {
        return Action::make('recalculateSchedule')
            ->label('Recalculate schedule')
            ->color('gray')
            ->icon(Heroicon::CalendarDays)
            ->visible(fn (Loan|GroupLoan $record): bool => in_array($record->status->value, ['disbursed', 'active'], true))
            ->authorize(fn (Loan|GroupLoan $record): bool => Filament::auth()->user()->hasRole(['branch_manager', 'company_admin'])
                && Filament::auth()->user()->company_id === $record->company_id)
            ->modalDescription('Re-dates every unpaid installment, one period apart. Amounts and payments are not changed.')
            ->schema(fn (Loan|GroupLoan $record): array => [
                Placeholder::make('current')
                    ->label('Next unpaid installment is due')
                    ->content(fn (): string => $record->installments()->where('status', '!=', 'paid')->orderBy('sequence')->first()?->due_date?->toFormattedDateString() ?? '—'),
                DatePicker::make('first_due_date')
                    ->label('New due date for the next unpaid installment')
                    ->helperText('Leave empty to rebuild the dates from the loan\'s own rule (disbursement / first repayment date).'),
                Textarea::make('reason')->maxLength(500),
            ])
            ->action(function (array $data, Loan|GroupLoan $record): void {
                try {
                    $result = app(RecalculateRepaymentScheduleAction::class)->execute(
                        $record,
                        Filament::auth()->user(),
                        filled($data['first_due_date'] ?? null) ? Carbon::parse($data['first_due_date']) : null,
                        $data['reason'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title("{$result['rescheduled']} installment(s) rescheduled")
                    ->body("Now due {$result['first_due_date']} to {$result['last_due_date']}.")
                    ->success()
                    ->send();
            });
    }

    public static function restructure(): Action
    {
        return Action::make('restructure')
            ->color('warning')
            ->icon(Heroicon::ArrowPath)
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
            });
    }

    public static function topUp(): Action
    {
        return Action::make('topUp')
            ->label('Top up')
            ->color('info')
            ->icon(Heroicon::PlusCircle)
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
                    Money::toMinorUnits($data['amount']),
                    $data['reason'],
                );
                Notification::make()->title("Loan topped up into {$newLoan->loan_number}")->success()->send();
            });
    }

    public static function writeOff(): Action
    {
        return Action::make('writeOff')
            ->label('Write off')
            ->color('danger')
            ->icon(Heroicon::ArchiveBoxXMark)
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
            });
    }
}
