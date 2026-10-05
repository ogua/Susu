<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\AccountStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Customer;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Read-only customer profile: money at a glance first, then who they are. */
class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Overview')
                ->columns(['default' => 2, 'lg' => 5])
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('savings_total')
                        ->label('Savings balance')
                        ->state(fn (Customer $record): string => Money::format((int) $record->savingsAccounts()->where('status', AccountStatus::Active)->sum('balance')))
                        ->size('lg')->weight('bold')->color('success'),
                    TextEntry::make('loan_total')
                        ->label('Loan balance')
                        ->state(fn (Customer $record): string => Money::format(
                            (int) $record->loans()->where('status', LoanStatus::Disbursed)->sum('outstanding_balance')
                            + (int) $record->groupLoans()->where('status', GroupLoanStatus::Active)->sum('outstanding_balance')
                        ))
                        ->size('lg')->weight('bold')->color('danger'),
                    TextEntry::make('accounts')
                        ->label('Savings accounts')
                        ->state(fn (Customer $record): string => $record->savingsAccounts()->where('status', AccountStatus::Active)->pluck('account_number')->implode(', ') ?: 'None open'),
                    TextEntry::make('groups')
                        ->label('Customer group')
                        ->state(fn (Customer $record): string => $record->loanGroupMemberships()->where('status', 'active')->with('loanGroup')->get()->pluck('loanGroup.name')->filter()->implode(', ') ?: '—'),
                    TextEntry::make('pending_withdrawals')
                        ->label('Withdrawal requests')
                        ->state(fn (Customer $record): string => (string) $record->withdrawalRequests()->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])->count()),
                ]),
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    Section::make('Client details')
                        ->columns(2)
                        ->columnSpan(['lg' => 2])
                        ->schema([
                            TextEntry::make('customer_code')->label('Client #')->copyable(),
                            TextEntry::make('name')->state(fn (Customer $record): string => $record->business_name && $record->client_type?->value === 'business' ? $record->business_name : $record->fullName()),
                            TextEntry::make('client_type')->badge(),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('phone'),
                            TextEntry::make('email')->placeholder('—'),
                            TextEntry::make('gender')->placeholder('—'),
                            TextEntry::make('date_of_birth')->date()->placeholder('—'),
                            TextEntry::make('external_id')->label('External ID')->placeholder('—'),
                            TextEntry::make('nationality')->placeholder('—'),
                            TextEntry::make('address')->label('Address')->placeholder('—')->columnSpanFull(),
                            TextEntry::make('city_town')->label('City/Town')->placeholder('—'),
                            TextEntry::make('digital_address')->label('Digital address')->placeholder('—'),
                        ]),
                    Section::make('Assignment')
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('branch.name')->label('Branch')->badge(),
                            TextEntry::make('assignedAgent.name')->label('Field agent')->placeholder('Unassigned'),
                            TextEntry::make('registeredBy.name')->label('Registered by')->placeholder('—'),
                            TextEntry::make('created_at')->label('Registered on')->date(),
                            TextEntry::make('next_of_kin_name')->label('Next of kin')
                                ->formatStateUsing(fn (Customer $record): string => trim($record->next_of_kin_name.' '.($record->next_of_kin_phone ? "({$record->next_of_kin_phone})" : '')))
                                ->placeholder('—'),
                        ]),
                ]),
        ]);
    }
}
