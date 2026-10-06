<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Schemas;

use App\Models\Company;
use App\Support\Money;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Reads the aggregates ViewCompany loads through BuildCompanyUsageReportAction
 * (branches_count, company_admins_count, savings_balance, …).
 */
class CompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Onboarding')
                    ->description('A company can only be used once it has a branch and an active company admin.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Suspended')
                            ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                        TextEntry::make('branches_count')
                            ->label('Branches')
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                            ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : 'None — add a branch'),
                        TextEntry::make('company_admins_count')
                            ->label('Active company admins')
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                            ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : 'None — add a company admin'),
                    ]),

                Section::make('Portfolio')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('staff_count')->label('Staff'),
                        TextEntry::make('customers_count')->label('Customers'),
                        TextEntry::make('active_savings_accounts_count')->label('Active savings accounts'),
                        TextEntry::make('last_activity_at')->label('Last transaction')->dateTime()->placeholder('Never'),
                        TextEntry::make('savings_balance')
                            ->label('Savings held')
                            ->state(fn (Company $record): string => Money::format((int) $record->savings_balance)),
                        TextEntry::make('loans_outstanding')
                            ->label('Loans outstanding')
                            ->state(fn (Company $record): string => Money::format((int) $record->loans_outstanding)),
                        TextEntry::make('new_customers_count')->label('New customers (this month)'),
                        TextEntry::make('collections_amount')
                            ->label('Collections (this month)')
                            ->state(fn (Company $record): string => Money::format((int) $record->collections_amount)
                                .' · '.$record->collections_count.' entries'),
                    ]),

                Section::make('Details')
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        ImageEntry::make('logo')->disk('public')->visibility('public')->placeholder('No logo'),
                        TextEntry::make('slug'),
                        TextEntry::make('domain_alias')->placeholder('—'),
                        TextEntry::make('contact_email')->copyable(),
                        TextEntry::make('contact_phone')->copyable(),
                        TextEntry::make('website')->placeholder('—'),
                        TextEntry::make('address')->placeholder('—'),
                        TextEntry::make('created_at')->label('Onboarded')->dateTime(),
                    ]),
            ]);
    }
}
