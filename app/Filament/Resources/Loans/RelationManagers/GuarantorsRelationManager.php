<?php

namespace App\Filament\Resources\Loans\RelationManagers;

use App\Filament\Resources\Loans\Schemas\LoanApplicationFields;
use App\Models\LoanGuarantor;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** People standing surety for the loan — added/edited in a modal. */
class GuarantorsRelationManager extends RelationManager
{
    protected static string $relationship = 'guarantors';

    protected static ?string $title = 'Guarantors';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(LoanApplicationFields::guarantorFields(fn (): ?string => $this->getOwnerRecord()->customer_id));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (LoanGuarantor $record): ?string => $record->customer_id ? 'Existing client' : null),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('relationship')->placeholder('—'),
                TextColumn::make('guaranteed_amount')
                    ->label('Guaranteed')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Money::format($state)),
            ])
            ->headerActions([CreateAction::make()->label('Add guarantor')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No guarantors recorded');
    }
}
