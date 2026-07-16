<?php

namespace App\Filament\SuperAdmin\Resources\Companies\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * No delete action — branches have customers/savings accounts/loans/journal
 * entries hanging off them, same reasoning as CompanyResource's no-delete
 * decision. No is_active column (branches don't have one, unlike Company).
 */
class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('slug')
                    ->required()
                    ->alphaDash()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule) => $rule->where('company_id', $this->getOwnerRecord()->id),
                    ),
                TextInput::make('code')->maxLength(10),
                TextInput::make('contact_phone')->tel(),
                TextInput::make('contact_email')->email(),
                Textarea::make('address')->columnSpanFull(),
            ]);
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
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
