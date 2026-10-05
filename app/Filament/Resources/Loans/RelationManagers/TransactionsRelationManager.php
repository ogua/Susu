<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Models\JournalEntry;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Every ledger posting on the loan's receivable: disbursement, repayments, write-off. */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'receivableLines';

    protected static ?string $title = 'Transactions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('entry.recordedBy'))
            ->columns([
                TextColumn::make('entry.recorded_at')->label('Date')->dateTime(),
                TextColumn::make('entry.type')->label('Type')->badge(),
                TextColumn::make('entry.reference')->label('Reference')->copyable(),
                TextColumn::make('debit')
                    ->label('Disbursed / charged')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—'),
                TextColumn::make('credit')
                    ->label('Repaid / reduced')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—'),
                TextColumn::make('entry.recordedBy.name')->label('By')->placeholder('—'),
                TextColumn::make('entry.status')->label('Status')->badge(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc(
                JournalEntry::select('recorded_at')->whereColumn('journal_entries.id', 'journal_lines.journal_entry_id'),
            ))
            ->emptyStateHeading('No transactions yet')
            ->emptyStateDescription('Postings appear once the loan is disbursed.');
    }
}
