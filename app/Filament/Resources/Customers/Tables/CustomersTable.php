<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\AccountStatus;
use App\Enums\GroupLoanStatus;
use App\Enums\LoanStatus;
use App\Filament\Resources\Customers\CustomerActions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['branch', 'assignedAgent', 'loanGroupMemberships' => fn ($members) => $members->where('status', 'active')->with('loanGroup')])
                ->withCount(['savingsAccounts as active_accounts_count' => fn (Builder $accounts) => $accounts->where('status', AccountStatus::Active)])
                ->withSum(['savingsAccounts as savings_balance' => fn (Builder $accounts) => $accounts->where('status', AccountStatus::Active)], 'balance')
                ->withSum(['loans as loan_outstanding' => fn (Builder $loans) => $loans->where('status', LoanStatus::Disbursed)], 'outstanding_balance')
                ->withSum(['groupLoans as group_loan_outstanding' => fn (Builder $loans) => $loans->where('status', GroupLoanStatus::Active)], 'outstanding_balance'))
            ->columns([
                TextColumn::make('customer_code')->label('Code')->searchable()->sortable()->copyable(),
                TextColumn::make('first_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name', 'business_name'])
                    ->sortable()
                    ->formatStateUsing(fn (Customer $record): string => $record->client_type?->value === 'business' && $record->business_name
                        ? $record->business_name
                        : $record->fullName())
                    ->description(fn (Customer $record): ?string => $record->client_type?->value === 'business' ? 'Business' : null),
                TextColumn::make('phone')->searchable(),
                TextColumn::make('branch.name')->label('Branch')->badge()->color('gray'),
                TextColumn::make('assignedAgent.name')->label('Agent')->placeholder('Unassigned'),
                TextColumn::make('active_accounts_count')->label('Accounts')->alignCenter(),
                TextColumn::make('savings_balance')
                    ->label('Savings')
                    ->formatStateUsing(fn ($state): string => Money::format((int) $state))
                    ->placeholder(Money::format(0))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('loan_balance')
                    ->label('Loan balance')
                    ->state(fn (Customer $record): int => (int) $record->loan_outstanding + (int) $record->group_loan_outstanding)
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—')
                    ->alignEnd(),
                TextColumn::make('group')
                    ->label('Group')
                    ->state(fn (Customer $record): ?string => $record->loanGroupMemberships->first()?->loanGroup?->name)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('client_type')->label('Type')->badge()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('user_id')->label('Has login')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label('Registered')->date()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('assigned_agent_id')
                    ->label('Agent')
                    ->relationship('assignedAgent', 'name'),
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole(['company_admin', 'super_admin']) ?? false),
                SelectFilter::make('client_type')->options([
                    'individual' => 'Individual',
                    'business' => 'Business',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    CustomerActions::assignAgent(),
                    CustomerActions::transfer(),
                    CustomerActions::addToGroup(),
                    CustomerActions::guardedDelete(DeleteAction::make()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    CustomerActions::bulkAssignAgent(),
                    CustomerActions::bulkTransfer(),
                    CustomerActions::bulkAddToGroup(),
                ]),
            ])
            ->recordUrl(fn (Customer $record): string => CustomerResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc');
    }
}
