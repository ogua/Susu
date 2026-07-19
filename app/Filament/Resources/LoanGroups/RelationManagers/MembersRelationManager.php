<?php

namespace App\Filament\Resources\LoanGroups\RelationManagers;

use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Actions\LoanGroups\RemoveLoanGroupMemberAction;
use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\LoanGroupMember;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Unlike susu groups, membership has no activation freeze — members can be added/removed anytime. */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('customer.first_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($record) => $record->customer->fullName()),
                TextColumn::make('status')->badge(),
                TextColumn::make('joined_at')->dateTime(),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Add Member')
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
                    ])
                    ->action(function (array $data): void {
                        /** @var LoanGroup $loanGroup */
                        $loanGroup = $this->getOwnerRecord();

                        app(AddLoanGroupMemberAction::class)->execute(
                            $loanGroup,
                            Customer::findOrFail($data['customer_id']),
                        );

                        Notification::make()->title('Member added')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('removeMember')
                    ->color('danger')
                    ->visible(fn (LoanGroupMember $record): bool => $record->status === 'active')
                    ->authorize(fn (): bool => Filament::auth()->user()->can('update', $this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->action(function (LoanGroupMember $record): void {
                        app(RemoveLoanGroupMemberAction::class)->execute($record);
                        Notification::make()->title('Member removed')->success()->send();
                    }),
            ])
            ->defaultSort('joined_at');
    }
}
