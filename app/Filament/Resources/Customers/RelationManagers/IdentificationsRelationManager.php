<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Actions\Customers\SaveCustomerIdentificationAction;
use App\Filament\Resources\Customers\Schemas\CustomerFormFields;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One ID record per modal instead of a cramped inline table. Saves go through
 * SaveCustomerIdentificationAction so the single-primary rule and the legacy
 * id_type/id_number mirror stay correct.
 */
class IdentificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'identifications';

    protected static ?string $title = 'Identifications';

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
        return $schema->columns(2)->components(CustomerFormFields::identificationFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id_type')
            ->columns([
                TextColumn::make('id_type')->label('ID type')->badge(),
                TextColumn::make('id_number')->label('ID number'),
                TextColumn::make('issue_date')->date(),
                TextColumn::make('expiry_date')->date()->placeholder('—'),
                TextColumn::make('description')->placeholder('—')->wrap(),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add identification')
                    ->using(fn (array $data): Model => app(SaveCustomerIdentificationAction::class)
                        ->execute($this->getOwnerRecord(), $data)),
            ])
            ->recordActions([
                Action::make('makePrimary')
                    ->label('Make primary')
                    ->color('gray')
                    ->visible(fn (CustomerIdentification $record): bool => ! $record->is_primary)
                    ->action(fn (CustomerIdentification $record): CustomerIdentification => app(SaveCustomerIdentificationAction::class)
                        ->execute($this->getOwnerRecord(), ['is_primary' => true], $record)),
                EditAction::make()
                    ->using(fn (CustomerIdentification $record, array $data): Model => app(SaveCustomerIdentificationAction::class)
                        ->execute($this->getOwnerRecord(), $data, $record)),
                DeleteAction::make()
                    ->using(fn (CustomerIdentification $record) => app(SaveCustomerIdentificationAction::class)->delete($record)),
            ])
            ->emptyStateHeading('No identification on file')
            ->emptyStateDescription('Add the customer\'s Ghana Card, voter ID or passport.');
    }
}
