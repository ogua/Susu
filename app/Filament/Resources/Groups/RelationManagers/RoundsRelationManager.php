<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Actions\Groups\PayoutGroupRoundAction;
use App\Actions\Groups\RecordGroupContributionAction;
use App\Enums\ClientOrigin;
use App\Models\GroupMember;
use App\Models\GroupRound;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Rounds are generated once at activation (ActivateGroupAction) — never created/edited here. */
class RoundsRelationManager extends RelationManager
{
    protected static string $relationship = 'rounds';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('round_number')
            ->columns([
                TextColumn::make('round_number')->label('#'),
                TextColumn::make('payoutMember.customer.first_name')
                    ->label('Payout to')
                    ->formatStateUsing(fn ($record) => $record->payoutMember->customer->fullName()),
                TextColumn::make('due_date')->date(),
                TextColumn::make('total_collected')
                    ->label('Collected')
                    ->formatStateUsing(fn (int $state, GroupRound $record): string => Money::format($state).' / '.Money::format($record->total_expected)),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                Action::make('recordContribution')
                    ->label('Record Contribution')
                    ->visible(fn (GroupRound $record): bool => $record->status->value !== 'completed')
                    ->authorize(fn (GroupRound $record): bool => Filament::auth()->user()->can('recordContribution', $record->group))
                    ->schema(fn (GroupRound $record) => [
                        Select::make('group_member_id')
                            ->label('Member')
                            ->options(fn (): array => $record->group->members()
                                ->whereDoesntHave('contributions', fn ($query) => $query->where('group_round_id', $record->id))
                                ->get()
                                ->mapWithKeys(fn (GroupMember $member) => [$member->id => $member->customer->fullName()])
                                ->all())
                            ->required(),
                    ])
                    ->action(function (array $data, GroupRound $record): void {
                        $member = GroupMember::findOrFail($data['group_member_id']);

                        app(RecordGroupContributionAction::class)->execute(
                            recordedBy: Filament::auth()->user(),
                            member: $member,
                            origin: ClientOrigin::Web,
                        );

                        Notification::make()->title('Contribution recorded')->success()->send();
                    }),
                Action::make('payout')
                    ->label('Payout')
                    ->color('primary')
                    ->visible(fn (GroupRound $record): bool => $record->status->value === 'collecting')
                    ->authorize(fn (GroupRound $record): bool => Filament::auth()->user()->can('payout', $record->group))
                    ->schema([
                        Toggle::make('override')
                            ->label('Pay out even though collection is incomplete'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (array $data, GroupRound $record): void {
                        app(PayoutGroupRoundAction::class)->execute(
                            $record,
                            Filament::auth()->user(),
                            override: (bool) ($data['override'] ?? false),
                        );

                        Notification::make()->title('Round paid out')->success()->send();
                    }),
            ])
            ->defaultSort('round_number');
    }
}
