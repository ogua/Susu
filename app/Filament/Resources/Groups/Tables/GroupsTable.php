<?php

namespace App\Filament\Resources\Groups\Tables;

use App\Actions\Groups\ActivateGroupAction;
use App\Actions\Groups\AddGroupMemberAction;
use App\Models\Customer;
use App\Models\Group;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('contribution_amount')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('frequency')->badge(),
                TextColumn::make('members_count')->label('Members')->counts('members'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'active' => 'Active',
                    'completed' => 'Completed',
                ]),
            ])
            ->recordActions([
                Action::make('addMember')
                    ->label('Add Member')
                    ->color('primary')
                    ->visible(fn (Group $record): bool => $record->status->value === 'draft')
                    ->authorize('update')
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
                    ->action(function (array $data, Group $record): void {
                        app(AddGroupMemberAction::class)->execute(
                            $record,
                            Customer::findOrFail($data['customer_id']),
                            (int) $data['rotation_position'],
                        );

                        Notification::make()->title('Member added')->success()->send();
                    }),
                Action::make('activate')
                    ->color('success')
                    ->visible(fn (Group $record): bool => $record->status->value === 'draft')
                    ->authorize('activate')
                    ->requiresConfirmation()
                    ->modalDescription('This locks in the rotation and generates every round upfront. Members cannot be added afterward.')
                    ->action(function (Group $record): void {
                        app(ActivateGroupAction::class)->execute($record);
                        Notification::make()->title('Group activated')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
