<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Actions\Groups\AddGroupMemberAction;
use App\Models\Customer;
use App\Models\Group;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Membership (and rotation order) is fixed once the group is activated. */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('rotation_position')->label('#')->sortable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName()),
                TextColumn::make('status')->badge(),
                TextColumn::make('joined_at')->dateTime(),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Add Member')
                    ->visible(fn (): bool => $this->getOwnerRecord()->status->value === 'draft')
                    ->authorize(fn (): bool => Filament::auth()->user()->can('update', $this->getOwnerRecord()))
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->options(fn (): array => Customer::where('branch_id', Filament::getTenant()?->id)
                                ->get()
                                ->mapWithKeys(fn ($customer) => [$customer->id => $customer->fullName().' ('.$customer->customer_code.')'])
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('rotation_position')
                            ->label('Rotation position')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var Group $group */
                        $group = $this->getOwnerRecord();

                        app(AddGroupMemberAction::class)->execute(
                            $group,
                            Customer::findOrFail($data['customer_id']),
                            (int) $data['rotation_position'],
                        );

                        Notification::make()->title('Member added')->success()->send();
                    }),
            ])
            ->defaultSort('rotation_position');
    }
}
