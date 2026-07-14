<?php

namespace App\Filament\Pages;

use App\Models\LedgerAccount;
use App\Support\Money;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every ledger account for the company, split into its normal-balance
 * column (App\Enums\LedgerAccountType::normalBalance()) — total debits
 * must equal total credits, proving the double-entry books are sound.
 * Company-wide rather than branch-scoped: system income/expense accounts
 * (e.g. commission income) carry no branch_id, so a branch-scoped view
 * would silently omit them.
 */
class TrialBalance extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.trial-balance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Trial Balance';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    public function totalDebits(): int
    {
        return $this->accountsQuery()
            ->get()
            ->sum(fn (LedgerAccount $account) => $account->type->normalBalance() === 'debit' ? $account->balance : 0);
    }

    public function totalCredits(): int
    {
        return $this->accountsQuery()
            ->get()
            ->sum(fn (LedgerAccount $account) => $account->type->normalBalance() === 'credit' ? $account->balance : 0);
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits();
    }

    /**
     * @return Builder<LedgerAccount>
     */
    private function accountsQuery(): Builder
    {
        return LedgerAccount::query()->where('company_id', Filament::getTenant()?->company_id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->accountsQuery())
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('debit')
                    ->state(fn (LedgerAccount $record): string => $record->type->normalBalance() === 'debit'
                        ? Money::format($record->balance) : '')
                    ->alignEnd(),
                TextColumn::make('credit')
                    ->state(fn (LedgerAccount $record): string => $record->type->normalBalance() === 'credit'
                        ? Money::format($record->balance) : '')
                    ->alignEnd(),
            ])
            ->defaultSort('type')
            ->paginated(false);
    }
}
