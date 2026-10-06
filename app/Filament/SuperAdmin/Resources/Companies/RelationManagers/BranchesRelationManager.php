<?php

namespace App\Filament\SuperAdmin\Resources\Companies\RelationManagers;

use App\Actions\Company\CreateBranchAction;
use App\Filament\SuperAdmin\Resources\Companies\Schemas\BranchForm;
use App\Models\Branch;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * No delete action — branches have customers/savings accounts/loans/journal
 * entries hanging off them, same reasoning as CompanyResource's no-delete
 * decision. No is_active column (branches don't have one, unlike Company).
 *
 * Creating goes through CreateBranchAction so the company's admins are
 * granted access to the new branch.
 */
class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(BranchForm::fields(fn (): string => $this->getOwnerRecord()->getKey()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('contact_phone'),
                TextColumn::make('contact_email'),
                TextColumn::make('users_count')->counts('users')->label('Users'),
                TextColumn::make('customers_count')->counts('customers')->label('Customers'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Branch => app(CreateBranchAction::class)->execute($this->getOwnerRecord(), $data)),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
