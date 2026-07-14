<?php

namespace App\Filament\Pages;

use App\Enums\InstallmentStatus;
use App\Models\LoanInstallment;
use App\Support\Money;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Every overdue loan installment for the tenant branch, oldest-first — the
 * field agent's/manager's chase list. Flagged as overdue (and penalized) by
 * the loans:flag-arrears command, not computed here, so this stays in sync
 * with whatever already accrued against the loan.
 */
class DefaultersReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.defaulters-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Defaulters';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager', 'field_agent']) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LoanInstallment::query()
                    ->where('status', InstallmentStatus::Overdue)
                    ->whereHas('loan', fn ($query) => $query->where('branch_id', Filament::getTenant()?->id))
                    ->with(['loan.customer', 'loan.agent'])
            )
            ->columns([
                TextColumn::make('loan.loan_number')->label('Loan #')->searchable(),
                TextColumn::make('loan.customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->loan->customer->fullName())
                    ->searchable(['loan.customer.first_name', 'loan.customer.last_name']),
                TextColumn::make('loan.customer.phone')->label('Phone'),
                TextColumn::make('loan.agent.name')->label('Agent'),
                TextColumn::make('due_date')
                    ->label('Days Overdue')
                    ->state(fn (LoanInstallment $record): int => (int) $record->due_date->diffInDays(now()))
                    ->sortable(),
                TextColumn::make('remaining')
                    ->label('Amount Due')
                    ->state(fn (LoanInstallment $record): string => Money::format($record->remaining()))
                    ->alignEnd(),
            ])
            ->defaultSort('due_date')
            ->emptyStateHeading('No defaulters — every installment is current.');
    }
}
