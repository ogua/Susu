<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Customers\Schemas\CustomerFormFields;
use App\Models\Customer;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Family members are added and edited one at a time in a modal. */
class FamilyMembersRelationManager extends RelationManager
{
    protected static string $relationship = 'familyMembers';

    protected static ?string $title = 'Family';

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
        return $schema->columns(2)->components(CustomerFormFields::familyMemberFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('relationship')->placeholder('—'),
                TextColumn::make('contact_phone')->placeholder('—'),
                TextColumn::make('occupation')->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->label('Add family member')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No family members recorded');
    }
}
