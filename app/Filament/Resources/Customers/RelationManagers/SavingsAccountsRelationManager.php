<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Filament\Resources\SavingsAccounts\Tables\SavingsAccountsTable;
use App\Models\SavingsAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: accounts are opened via SavingsAccountResource so the ledger
 * sub-account gets created (OpenSavingsAccountAction) — this list just
 * gives quick visibility + a fast "Record Collection" action per account.
 */
class SavingsAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'savingsAccounts';

    protected static ?string $title = 'Savings Accounts';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account_number')
            ->columns([
                TextColumn::make('account_number')->searchable(),
                TextColumn::make('product.name')->label('Product'),
                TextColumn::make('agent.name')->label('Agent'),
                TextColumn::make('balance')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                Action::make('recordCollection')
                    ->label('Record Collection')
                    ->visible(fn (SavingsAccount $record): bool => $record->acceptsCollections())
                    ->authorize('recordCollection')
                    ->schema(fn (SavingsAccount $record): array => [
                        SavingsAccountsTable::collectionAmountField($record),
                    ])
                    ->action(function (array $data, $record): void {
                        app(RecordCollectionAction::class)->execute(
                            agent: Filament::auth()->user(),
                            account: $record,
                            amount: Money::toMinorUnits($data['amount']),
                            origin: ClientOrigin::Web,
                        );

                        Notification::make()->title('Collection recorded')->success()->send();
                    }),
            ]);
    }
}
