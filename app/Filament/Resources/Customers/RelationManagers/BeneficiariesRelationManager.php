<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Customers\Schemas\CustomerFormFields;
use App\Models\Customer;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Beneficiaries are added and edited one at a time in a modal. */
class BeneficiariesRelationManager extends RelationManager
{
    protected static string $relationship = 'beneficiaries';

    protected static ?string $title = 'Beneficiaries';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Customer && $ownerRecord->client_type?->value !== 'business';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(CustomerFormFields::beneficiaryFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('relationship')->placeholder('—'),
                TextColumn::make('amount_of_legacy')
                    ->label('Legacy')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn (?int $state): string => Money::format((int) $state))),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('town')->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->label('Add beneficiary')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No beneficiaries');
    }
}
