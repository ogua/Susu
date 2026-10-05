<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Filament\Resources\Loans\Schemas\LoanApplicationFields;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Pledged assets — added/edited in a modal at any point in the loan's life. */
class CollateralsRelationManager extends RelationManager
{
    protected static string $relationship = 'collaterals';

    protected static ?string $title = 'Collateral';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(LoanApplicationFields::collateralFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('description')->wrap(),
                TextColumn::make('serial_number')->label('Serial / reference')->placeholder('—'),
                TextColumn::make('estimated_value')
                    ->label('Estimated value')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn (?int $state): string => Money::format((int) $state))),
            ])
            ->headerActions([CreateAction::make()->label('Add collateral')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No collateral recorded');
    }
}
