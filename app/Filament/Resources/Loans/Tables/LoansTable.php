<?php

namespace App\Filament\Resources\Loans\Tables;

use App\Enums\InstallmentStatus;
use App\Filament\Resources\Loans\LoanActions;
use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Services\Loans\EligibilityService;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['customer', 'loanProduct', 'agent', 'branch', 'savingsAccount'])
                ->withSum(
                    ['installments as overdue_amount' => fn (Builder $installments) => $installments
                        ->where('status', '!=', InstallmentStatus::Paid)
                        ->whereDate('due_date', '<', today())],
                    DB::raw('principal_due + interest_due + penalty_due - principal_paid - interest_paid - penalty_paid'),
                ))
            ->columns([
                TextColumn::make('loan_number')->label('Loan #')->searchable()->sortable(),
                TextColumn::make('customer.first_name')
                    ->label('Client')
                    ->searchable(['first_name', 'last_name'])
                    ->formatStateUsing(fn (Loan $record): string => $record->customer->fullName()),
                TextColumn::make('customer.phone')->label('Phone')->searchable(),
                TextColumn::make('principal_amount')->label('Loan amount')->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd()->sortable(),
                TextColumn::make('outstanding_balance')->label('Balance')->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd()->sortable(),
                TextColumn::make('overdue_amount')
                    ->label('Overdue')
                    ->formatStateUsing(fn ($state): string => Money::format(max(0, (int) $state)))
                    ->placeholder(Money::format(0))
                    ->color(fn ($state): ?string => (int) $state > 0 ? 'danger' : null)
                    ->alignEnd(),
                TextColumn::make('loanProduct.name')->label('Product'),
                TextColumn::make('agent.name')->label('Loan officer')->placeholder('—'),
                TextColumn::make('branch.name')->label('Branch')->toggleable(),
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
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applied_at')->dateTime()->sortable()->toggleable(),
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
                    'disbursed' => 'Active (disbursed)',
                    'closed' => 'Closed',
                    'written_off' => 'Written off',
                    'refinanced' => 'Refinanced',
                ]),
                SelectFilter::make('agent_id')->label('Loan officer')->relationship('agent', 'name'),
                SelectFilter::make('loan_product_id')->label('Product')->relationship('loanProduct', 'name'),
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole(['company_admin', 'super_admin']) ?? false),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(LoanActions::all()),
            ])
            ->recordUrl(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record]))
            ->defaultSort('applied_at', 'desc');
    }
}
