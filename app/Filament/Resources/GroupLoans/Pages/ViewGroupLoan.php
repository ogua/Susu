<?php

namespace App\Filament\Resources\GroupLoans\Pages;

use App\Filament\Resources\GroupLoans\GroupLoanResource;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewGroupLoan extends ViewRecord
{
    protected static string $resource = GroupLoanResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Loan')->schema([
                TextEntry::make('loan_number'),
                TextEntry::make('loanGroup.name')->label('Loan group'),
                TextEntry::make('customer.first_name')->label('Member')
                    ->formatStateUsing(fn ($record): string => $record->customer->fullName()),
                TextEntry::make('status')->badge(),
                TextEntry::make('deposit_status')->badge(),
                TextEntry::make('repayment_frequency'),
                TextEntry::make('start_date')->date(),
                TextEntry::make('total_periods'),
            ])->columns(3),
            Section::make('Amounts')->schema([
                TextEntry::make('principal_amount')->label('Loan amount')
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextEntry::make('security_deposit_amount')->label('Security deposit')
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextEntry::make('periodic_amount')->label('Amount to be paid')
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextEntry::make('amount_repaid')->label('Repaid so far')
                    ->state(fn ($record): string => Money::format($record->amountRepaid())),
                TextEntry::make('outstanding_balance')->label('Loan outstanding')
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
            ])->columns(3),
        ]);
    }
}
