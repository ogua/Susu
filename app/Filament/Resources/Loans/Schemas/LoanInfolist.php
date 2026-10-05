<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Enums\InstallmentStatus;
use App\Models\Loan;
use App\Support\Money;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Loan details page, laid out like eBanQR's: who/what plus the money at a
 * glance (balance, paid, charges, overdue), then Overview and Details tabs.
 * Schedule, transactions, collateral, guarantors, charges and history are
 * the relation-manager tabs below.
 */
class LoanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Loan $record): string => $record->customer->fullName())
                ->description(fn (Loan $record): string => 'Client #'.$record->customer->customer_code.' · '.($record->loanProduct?->name ?? 'Loan').' · Loan #'.$record->loan_number)
                ->columns(['default' => 2, 'md' => 5])
                ->columnSpanFull()
                ->schema([
                    self::amount('balance', 'Loan balance', fn (Loan $record): int => $record->outstanding_balance, 'primary'),
                    self::amount('paid', 'Amount paid', fn (Loan $record): int => (int) $record->installments->sum(fn ($installment): int => $installment->amountPaid()), 'success'),
                    self::amount('charges_paid', 'Charges', fn (Loan $record): int => $record->origination_fee_amount, 'gray'),
                    self::amount('overdue', 'Amount overdue', fn (Loan $record): int => self::overdue($record), 'danger'),
                    TextEntry::make('status')->badge()->size('lg'),
                ]),
            Tabs::make('Loan')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Overview')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            Section::make('General')
                                ->columns(2)
                                ->schema([
                                    TextEntry::make('status')->badge(),
                                    TextEntry::make('purpose')->label('Loan purpose')->placeholder('—'),
                                    TextEntry::make('agent.name')->label('Loan officer')->placeholder('—'),
                                    TextEntry::make('branch.name')->label('Branch'),
                                    TextEntry::make('savingsAccount.account_number')->label('Linked savings')->placeholder('—'),
                                    TextEntry::make('approvedBy.name')->label('Approved by')->placeholder('—'),
                                ]),
                            Section::make('Summary')
                                ->columns(2)
                                ->schema([
                                    self::money('principal_amount', 'Loan amount'),
                                    TextEntry::make('interest_rate_bps')->label('Interest rate')
                                        ->formatStateUsing(fn (int $state, Loan $record): string => ($state / 100).'% per '.$record->repayment_frequency->periodNoun()),
                                    self::money('total_interest', 'Interest amount'),
                                    self::money('total_repayable', 'Total repayable'),
                                    self::money('origination_fee_amount', 'Charges deducted'),
                                    TextEntry::make('net_disbursed')->label('Cash to client')
                                        ->state(fn (Loan $record): string => Money::format($record->principal_amount - $record->origination_fee_amount)),
                                ]),
                        ]),
                    Tab::make('Details')
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            Section::make('Dates')
                                ->schema([
                                    TextEntry::make('applied_at')->label('Applied on')->date()->placeholder('—'),
                                    TextEntry::make('approved_at')->label('Approved on')->date()->placeholder('—'),
                                    TextEntry::make('disbursed_at')->label('Disbursed on')->date()->placeholder('—'),
                                    TextEntry::make('matures_on')->label('Matures on')
                                        ->state(fn (Loan $record): ?string => $record->installments->last()?->due_date?->toFormattedDateString())
                                        ->placeholder('—'),
                                    TextEntry::make('closed_at')->label('Closed on')->date()->placeholder('—'),
                                ]),
                            Section::make('Repayment')
                                ->schema([
                                    TextEntry::make('repayments')->label('Repayments')
                                        ->state(fn (Loan $record): string => $record->term_period_count.' × '.$record->repayment_frequency->value),
                                    TextEntry::make('first_repayment_date')->label('First repayment date')->date()->placeholder('One period after disbursement'),
                                    TextEntry::make('grace_period_days')->label('Grace period')->suffix(' days'),
                                    TextEntry::make('penalty_rate_bps')->label('Late penalty')->formatStateUsing(fn (int $state): string => ($state / 100).'% of overdue amount'),
                                    TextEntry::make('strategy')->label('Repayment strategy')->state('Penalties, interest, principal — oldest first'),
                                ]),
                            Section::make('Interest')
                                ->schema([
                                    TextEntry::make('interest_method')->label('Interest type')->badge(),
                                    TextEntry::make('interest_rate_bps')->label('Rate per period')->formatStateUsing(fn (int $state): string => ($state / 100).'%'),
                                    TextEntry::make('guarantor_name')->label('Primary guarantor')->placeholder('—'),
                                    TextEntry::make('notes')->placeholder('—'),
                                    TextEntry::make('rejection_reason')->placeholder('—')->visible(fn (Loan $record): bool => filled($record->rejection_reason)),
                                ]),
                        ]),
                ]),
        ]);
    }

    private static function amount(string $name, string $label, Closure $value, string $color): TextEntry
    {
        return TextEntry::make("header_{$name}")
            ->label($label)
            ->state(fn (Loan $record): string => Money::format($value($record)))
            ->size('lg')
            ->weight('bold')
            ->color($color);
    }

    private static function money(string $attribute, string $label): TextEntry
    {
        return TextEntry::make($attribute)->label($label)->formatStateUsing(fn (?int $state): string => Money::format((int) $state));
    }

    /** Unpaid amount on installments already past their due date. */
    private static function overdue(Loan $record): int
    {
        return (int) $record->installments
            ->filter(fn ($installment): bool => $installment->status !== InstallmentStatus::Paid && $installment->due_date->isBefore(today()))
            ->sum(fn ($installment): int => ($installment->principal_due + $installment->interest_due + $installment->penalty_due) - $installment->amountPaid());
    }
}
